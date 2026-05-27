/* global OC */
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { DefaultType, File, Folder, Permission, getNavigation, registerFileAction, registerFileListFilter, View } from '@nextcloud/files'
import { getDefaultPropfind, parsePermissions } from '@nextcloud/files/dav'
import { t } from '@nextcloud/l10n'
import { createClient } from 'webdav'
import EyeSvg from '@mdi/svg/svg/eye.svg?raw'
import FolderGroupSvg from '@mdi/svg/svg/folder-account-outline.svg?raw'
import FolderSvg from '@mdi/svg/svg/folder-outline.svg?raw'

const OCS       = '/ocs/v2.php/apps/user_group_admin/api/v1'
const PARENT_ID = 'uga-grants'

// Hide the .uga_grants dotfolder from the normal Files view
try {
	registerFileListFilter({
		id:     'uga-hide-grant-dir',
		filter: nodes => nodes.filter(n => n.basename !== '.uga_grants'),
	})
} catch (e) {
	console.error('[user_group_admin] Failed to register file list filter', e)
}

function grantBaseUrl(gid) {
	return window.location.origin + (OC.webroot || '') + '/remote.php/user_group_admin/' + gid
}

/**
 * Build a Folder or File node for our custom DAV endpoint.
 *
 * The @nextcloud/files Node class only treats URLs matching
 * /(remote|public)\.php\/(web)?dav/ as DAV resources; anything else
 * returns Permission.READ regardless of oc:permissions. We pass a
 * per-group davService regex so the node class honours our permissions
 * and allows uploads.
 */
function resultToGrantNode(node, base, gid) {
	const escapedGid = gid.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const davService = new RegExp('remote\\.php\\/user_group_admin\\/' + escapedGid)

	const userId     = getCurrentUser()?.uid
	const props      = node.props ?? {}
	const id         = props.fileid ? Number(props.fileid) : 0
	const permissions = parsePermissions(props.permissions ?? '')
	const mtime      = new Date(Date.parse(node.lastmod))
	const crtime     = props.creationdate ? new Date(Date.parse(props.creationdate)) : undefined

	const nodeData = {
		id,
		source:      base + node.filename,
		mtime:       !isNaN(mtime.getTime()) && mtime.getTime() !== 0 ? mtime : undefined,
		crtime:      crtime && !isNaN(crtime.getTime()) ? crtime : undefined,
		mime:        node.mime || 'application/octet-stream',
		displayname: props.displayname !== undefined ? String(props.displayname) : undefined,
		size:        props.size || parseInt(props.getcontentlength || '0'),
		permissions,
		owner:       userId,
		root:        '/',
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

async function getGrantContents(gid, path, options) {
	const base    = grantBaseUrl(gid)
	const client  = createClient(base)
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
		folder:   resultToGrantNode(rootItem, base, gid),
		contents: contents.map(f => resultToGrantNode(f, base, gid)),
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
			// Pass a plain object so prototype-only getters (path→filename)
			// are included and the viewer's extractFilePaths() doesn't crash.
			window.OCA.Viewer.open({
				fileInfo: {
					fileid:      node.fileid,
					source:      node.source,
					filename:    node.path || '/',
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
	Navigation.register(new View({
		id:          `uga-grant-${group.gid}`,
		name:        group.gid,
		caption:     group.description || group.gid,
		icon:        FolderSvg,
		order:       0,
		parent:      PARENT_ID,
		getContents: (path, options) => getGrantContents(group.gid, path || '/', options),
	}))
}

Navigation.register(new View({
	id:            PARENT_ID,
	name:          t('user_group_admin', 'Grants'),
	caption:       t('user_group_admin', 'Group storage grants'),
	emptyTitle:    t('user_group_admin', 'No grants'),
	emptyCaption:  t('user_group_admin', 'You have no group storage grants assigned yet.'),
	icon:          FolderGroupSvg,
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
		return getGrantContents(gid, subPath || '/', options)
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
