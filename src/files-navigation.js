/* global OC */
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { DefaultType, File, FileListFilter, Folder, Permission, getNavigation, registerFileAction, registerFileListFilter, View } from '@nextcloud/files'
import { getDefaultPropfind, parsePermissions } from '@nextcloud/files/dav'
import { t } from '@nextcloud/l10n'
import { createClient } from 'webdav'
import EyeSvg from '@mdi/svg/svg/eye.svg?raw'
import GiftSvg from '@mdi/svg/svg/gift-outline.svg?raw'
import FolderSvg from '@mdi/svg/svg/folder-outline.svg?raw'

const OCS        = '/ocs/v2.php/apps/user_group_admin/api/v1'
const PARENT_ID  = 'uga-grants'
const GRANT_DIR  = '.uga_grants'

// Hide the .uga_grants dotfolder from the normal Files view (also catches legacy 'Grants')
try {
	class HideGrantDirFilter extends FileListFilter {
		filter(nodes) { return nodes.filter(n => n.basename !== '.uga_grants' && n.basename !== 'Grants') }
	}
	registerFileListFilter(new HideGrantDirFilter('uga-hide-grant-dir'))
} catch (e) {
	console.error('[user_group_admin] Failed to register file list filter', e)
}

function grantBaseUrl(gid) {
	return window.location.origin + (OC.webroot || '') + '/remote.php/user_group_admin/' + gid
}

function memberDavBase(gid) {
	// Standard NC DAV path for the current user's grant folder.
	// Using this as the file source keeps chunked uploads inside /remote.php/dav/
	// so NC's QuotaPlugin can validate the Destination header without throwing.
	const uid = getCurrentUser()?.uid ?? ''
	return window.location.origin + (OC.webroot || '')
		+ '/remote.php/dav/files/' + encodeURIComponent(uid)
		+ '/' + GRANT_DIR + '/' + encodeURIComponent(gid)
}

/**
 * Build a Folder or File node for a grant folder.
 *
 * isOwner=false (member): source uses the standard /remote.php/dav/ URL so that
 * @nextcloud/upload's chunked upload Destination header stays within the DAV tree
 * and NC's QuotaPlugin doesn't throw when calculating free space.
 *
 * isOwner=true: source uses the custom grant endpoint (the owner browses other
 * members' home dirs via the grant proxy; those paths don't exist in the owner's
 * standard DAV tree). The davService regex strips only up to 'user_group_admin/'
 * so that node.path includes /{gid}/{memberUid}/... — needed for navigation in
 * the parent 'uga-grants' view where getContents parses /{gid} from path[0].
 */
function resultToGrantNode(node, base, gid, isOwner) {
	const fileBase   = isOwner ? base : memberDavBase(gid)
	const davService = isOwner
		? /remote\.php\/user_group_admin\//
		: /remote\.php\/(web)?dav/

	const userId      = getCurrentUser()?.uid
	const props       = node.props ?? {}
	const id          = props.fileid ? Number(props.fileid) : 0
	const permissions = parsePermissions(props.permissions ?? '')
	const mtime       = new Date(Date.parse(node.lastmod))
	const crtime      = props.creationdate ? new Date(Date.parse(props.creationdate)) : undefined

	const nodeData = {
		id,
		source:      fileBase + node.filename,
		mtime:       !isNaN(mtime.getTime()) && mtime.getTime() !== 0 ? mtime : undefined,
		crtime:      crtime && !isNaN(crtime.getTime()) ? crtime : undefined,
		mime:        node.mime || 'application/octet-stream',
		displayname: props.displayname !== undefined ? String(props.displayname) : undefined,
		size:        props.size || parseInt(props.getcontentlength || '0'),
		permissions,
		owner:       userId,
		// root = user's files root so node.path = '/.uga_grants/{gid}/file.jpg',
		// which is what the OCS shares/tags APIs expect.
		// getContents strips the /.uga_grants/{gid} prefix before the DAV call.
		root:        isOwner ? '/' : '/files/' + (userId ?? ''),
		attributes:  {
			...node,
			...props,
			hasPreview: props['has-preview'],
		},
	}
	delete nodeData.attributes.props

	return node.type === 'file'
		? new File(nodeData, davService)
		: new Folder(nodeData, davService)
}

async function getGrantContents(gid, path, options, isOwner = false) {
	// Strip stale /.uga_grants/{gid} prefix. decodeURIComponent can throw — keeps try-catch.
	try {
		const decoded  = decodeURIComponent(path || '')
		const grantRoot = '/' + GRANT_DIR + '/' + gid
		if (decoded && decoded.includes(grantRoot)) {
			path = decoded.slice(decoded.indexOf(grantRoot) + grantRoot.length) || '/'
		}
	} catch (e) { /* ignore */ }

	// Owner view must use the custom grant endpoint (owner can't browse members'
	// files via standard DAV).  Member view uses the standard NC DAV path so that
	// PROPFIND returns real oc_filecache fileids — needed for systemtags, shares, etc.
	const base    = isOwner ? grantBaseUrl(gid) : memberDavBase(gid)
	const client  = createClient(base, { headers: { requesttoken: OC.requestToken } })
	const davPath = (!path || path === '/') ? '/' : path

	const resp = await client.getDirectoryContents(davPath, {
		details:     true,
		data:        getDefaultPropfind(),
		includeSelf: true,
		signal:      options?.signal,
	})

	const all      = resp.data
	const rootItem = all.find(f => f.filename === davPath || f.filename === '/') ?? all[0]
	const contents = all.filter(f => f !== rootItem)

	return {
		folder:   resultToGrantNode(rootItem, base, gid, isOwner),
		contents: contents.map(f => resultToGrantNode(f, base, gid, isOwner)),
	}
}

// ── Viewer action for grant folder files ─────────────────────────────────────
//
// OCA.Viewer's built-in action blocks any node whose root doesn't start with
// '/files'. We register a higher-priority action that calls OCA.Viewer.open()
// with fileInfo.source pointing directly at our custom DAV endpoint, which
// the viewer model accepts and uses without hitting the standard files tree.

try {
	registerFileAction({
		id:              'uga-grant-view',
		displayName:     () => t('user_group_admin', 'View'),
		iconSvgInline:   () => EyeSvg,
		default:         DefaultType.DEFAULT,
		order:           -1,
		enabled:         ({ nodes, view }) => {
			if (!view?.id?.startsWith('uga-grant')) return false
			if (!window.OCA?.Viewer?.mimetypes) return false
			return nodes.length === 1
				&& (nodes[0].permissions & Permission.READ) !== 0
				&& window.OCA.Viewer.mimetypes.includes(nodes[0].mime)
		},
		exec: async ({ nodes }) => {
			const node = nodes[0]
			// Derive the viewer filename from source, not node.path.
			// node.path with root='/' returns /files/{uid}/... (the full path after
			// the DAV service marker), but the viewer expects a path relative to
			// /remote.php/dav/files/{uid}/ so it can list siblings via standard DAV.
			const uid       = getCurrentUser()?.uid ?? ''
			const davPrefix = '/remote.php/dav/files/' + encodeURIComponent(uid)
			const srcPath   = new URL(node.source).pathname
			const filename  = srcPath.includes(davPrefix)
				? srcPath.slice(srcPath.indexOf(davPrefix) + davPrefix.length) || '/'
				: node.path || '/'
			window.OCA.Viewer.open({
				fileInfo: {
					fileid:      node.fileid,
					source:      node.source,
					filename,
					basename:    node.basename,
					mime:        node.mime,
					size:        node.size,
					permissions: node.attributes?.permissions || 'RGDNVCK',
				},
			})
			return null
		},
	})
} catch (e) {
	console.error('[user_group_admin] Failed to register grant viewer action', e)
}

// ── Navigation registration ───────────────────────────────────────────────────

const Navigation       = getNavigation()
const GROUPS_CACHE_KEY = 'uga_grant_groups_v1'
let   grantGroups      = []

function registerGroupView(group) {
	const isOwner  = getCurrentUser()?.uid === group.owner
	const grantRoot = '/' + GRANT_DIR + '/' + group.gid
	Navigation.register(new View({
		id:          `uga-grant-${group.gid}`,
		name:        group.gid,
		caption:     group.description || group.gid,
		icon:        FolderSvg,
		order:       0,
		parent:      PARENT_ID,
		// Link sidebar item to parent view URL (/apps/files/uga-grants?dir=/{gid}).
		// FilesNavigation.vue's param-matching then returns THIS child view as the
		// active navigation view (for sidebar highlighting), while the active app
		// view stays 'uga-grants' — so currentNavigationViewId is never undefined
		// even when 'uga-grant-{gid}' is not yet registered on a cold reload.
		params:      { view: PARENT_ID, dir: '/' + group.gid },
		getContents: (path, options) => {
			// Strip stale grantRoot prefix. decodeURIComponent may throw — keeps try-catch.
			try {
				const decoded = decodeURIComponent(path || '')
				if (decoded && decoded.includes(grantRoot)) {
					path = decoded.slice(decoded.indexOf(grantRoot) + grantRoot.length) || '/'
				}
			} catch (e) { /* ignore */ }
			return getGrantContents(group.gid, path || '/', options, isOwner)
		},
	}))
}

Navigation.register(new View({
	id:            PARENT_ID,
	name:          t('user_group_admin', 'Grants'),
	caption:       t('user_group_admin', 'Group storage grants'),
	emptyTitle:    t('user_group_admin', 'No files'),
	emptyCaption:  t('user_group_admin', 'This folder is empty.'),
	icon:          GiftSvg,
	order:         25,
	getContents:   async (path, options) => {
		if (!path || path === '/') {
			// Return synthetic Folder nodes for each grant group
			const origin = window.location.origin + (OC.webroot || '')
			const syntheticRoot = new Folder({
				id:          0,
				source:      origin + '/remote.php/user_group_admin/',
				owner:       OC.currentUser,
				permissions: 1,
				root:        '/',
			})
			const folders = grantGroups.map(g => new Folder({
				id:          0,
				source:      grantBaseUrl(g.gid) + '/',
				owner:       OC.currentUser,
				displayName: g.gid,
				permissions: 31,
				root:        '/',
			}))
			return { folder: syntheticRoot, contents: folders }
		}
		// User navigated into a grant subfolder via the file list
		const parts   = path.replace(/^\//, '').split('/')
		const gid     = parts[0]
		const subPath = '/' + parts.slice(1).join('/')
		const grp     = grantGroups.find(g => g.gid === gid)
		const isOwner = getCurrentUser()?.uid === grp?.owner
		return getGrantContents(gid, subPath || '/', options, isOwner)
	},
}))

// Seed child views synchronously from localStorage so that reloading a
// bookmarked /apps/files/uga-grant-{gid} URL works before the API responds.
try {
	const cached = JSON.parse(localStorage.getItem(GROUPS_CACHE_KEY) || '[]')
	for (const group of cached) {
		grantGroups.push(group)
		registerGroupView(group)
	}
} catch (e) { /* ignore corrupt cache */ }

// Restore navigation target that was temporarily redirected to 'files' by the
// files-navigation-init.js init script to prevent a crash in NC core's
// FilesNavigationListItem.vue on cold reload with a uga-grant* URL.
;(function restorePendingNavigation() {
	try {
		const raw = sessionStorage.getItem('__uga_pending')
		if (!raw || !window.OCP?.Files?.Router) return
		const { view, dir } = JSON.parse(raw)
		sessionStorage.removeItem('__uga_pending')
		let targetDir = dir
		if (view !== PARENT_ID) {
			// uga-grant-{gid} → convert to parent view path /{gid}/{subpath}
			const gid = view.replace(/^uga-grant-/, '')
			targetDir = dir === '/' ? '/' + gid : '/' + gid + dir
		}
		window.OCP.Files.Router.goToRoute(null, { view: PARENT_ID }, { dir: targetDir })
	} catch (e) { /* ignore */ }
})()

// Redirect old /apps/files/uga-grant-{gid}[/fileid][?dir=sub] URLs to the parent
// view URL scheme (/apps/files/uga-grants?dir=/{gid}[/sub]). This preserves the
// subpath so deep links survive the redirect. Triggers Vue Router navigation so
// FilesNavigation.vue re-evaluates currentNavigationViewId with the always-
// registered parent view — resolving any initial render error caused by the child
// view not being registered on a first-ever cold reload.
;(function redirectLegacyGrantUrl() {
	try {
		// Match uga-grant-{gid} with an optional /fileid segment after it
		const m = window.location.pathname.match(/\/apps\/files\/uga-grant-([^/?#/]+)/)
		if (!m || !window.OCP?.Files?.Router) return
		const gid = decodeURIComponent(m[1])
		const queryDir = new URLSearchParams(window.location.search).get('dir') || '/'
		// Prepend /{gid} to the old ?dir= subpath so the parent view gets the full path
		const newDir = queryDir === '/' ? '/' + gid : '/' + gid + queryDir
		// Route without fileid — including a real fileid causes NC to resolve it in the
		// standard files tree and redirect away from the grant view on reload.
		window.OCP.Files.Router.goToRoute(null, { view: PARENT_ID }, { dir: newDir })
	} catch (e) { /* ignore */ }
})()

// Load grant groups from API, refresh cache, and register any newly added groups
;(async () => {
	try {
		const { data } = await axios.get(`${OCS}/groups`, {
			headers: { 'OCS-APIREQUEST': 'true' },
		})
		const fresh = (data.ocs?.data ?? []).filter(g => g.storage_grant && g.storage_grant !== '')
		localStorage.setItem(GROUPS_CACHE_KEY, JSON.stringify(fresh))

		const registered = new Set(grantGroups.map(g => g.gid))
		grantGroups = fresh

		for (const group of fresh) {
			if (!registered.has(group.gid)) {
				registerGroupView(group)
			}
		}
	} catch (e) {
		console.error('[user_group_admin] Failed to load grant groups', e)
	}
})()
