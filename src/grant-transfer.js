/* global OC */
/**
 * "Move or copy" — the app's replacement for NC's stock Move-or-copy action: it
 * also moves or copies between the user's home and their grant folders. The
 * stock action ('move-copy') is hidden by css/files-navigation.css so users see
 * ONE entry; without any grants this behaves exactly like the stock one.
 *
 * Grant folders live at /.uga_grants/{gid}/ inside the user's own home storage,
 * hidden from the normal Files view and shown as the "Grants" view instead. NC's
 * stock "Move or copy" picker is rooted at "All files", filters hidden entries
 * and knows nothing about our views, so it cannot reach them (and from a grant
 * it cannot reach home). This action asks for the destination root — Home or
 * one of the user's grants (the old service's move-app dropdown) — then opens
 * NC's file picker inside that root and performs a plain WebDAV MOVE/COPY within
 * /remote.php/dav/files/{uid}/ (same storage; grant quota is enforced by the
 * uga_grant_quota storage wrapper, so a full grant answers 507 before anything
 * is written).
 */
import { emit } from '@nextcloud/event-bus'
import { getCurrentUser } from '@nextcloud/auth'
import { Permission, registerFileAction } from '@nextcloud/files'
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { createClient } from 'webdav'
import MoveSvg from '@mdi/svg/svg/folder-move-outline.svg?raw'

const GRANT_DIR = '.uga_grants'

function davClient() {
	const uid = getCurrentUser()?.uid ?? ''
	const root = window.location.origin + (OC.webroot || '') + '/remote.php/dav/files/' + encodeURIComponent(uid)
	return createClient(root, { headers: { requesttoken: OC.requestToken } })
}

/** Small vanilla root chooser: Home + one entry per grant. Resolves to {label, path} or null. */
function chooseRoot(roots) {
	return new Promise((resolve) => {
		const overlay = document.createElement('div')
		overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:10000;display:flex;align-items:center;justify-content:center'
		const box = document.createElement('div')
		box.style.cssText = 'background:var(--color-main-background);color:var(--color-main-text);border-radius:var(--border-radius-large);padding:20px 24px;min-width:320px;max-width:90vw;box-shadow:0 0 30px rgba(0,0,0,.3)'
		const title = document.createElement('h2')
		title.textContent = t('user_group_admin', 'Move or copy to…')
		title.style.cssText = 'margin:0 0 12px;font-size:20px'
		const label = document.createElement('label')
		label.textContent = t('user_group_admin', 'Destination')
		label.style.cssText = 'display:block;margin-bottom:6px'
		const select = document.createElement('select')
		select.style.cssText = 'width:100%;margin-bottom:16px'
		roots.forEach((r, i) => {
			const o = document.createElement('option')
			o.value = String(i)
			o.textContent = r.label
			select.appendChild(o)
		})
		const row = document.createElement('div')
		row.style.cssText = 'display:flex;gap:8px;justify-content:flex-end'
		const cancel = document.createElement('button')
		cancel.textContent = t('user_group_admin', 'Cancel')
		const ok = document.createElement('button')
		ok.textContent = t('user_group_admin', 'Choose folder…')
		ok.className = 'primary'
		const close = (val) => { overlay.remove(); resolve(val) }
		cancel.onclick = () => close(null)
		ok.onclick = () => close(roots[Number(select.value)] ?? null)
		overlay.onclick = (e) => { if (e.target === overlay) close(null) }
		row.append(cancel, ok)
		box.append(title, label, select, row)
		overlay.appendChild(box)
		document.body.appendChild(overlay)
		select.focus()
	})
}

async function transfer(nodes, grantGroups) {
	const roots = [{ label: t('user_group_admin', 'Home'), path: '/' }]
	for (const g of grantGroups) {
		roots.push({ label: t('user_group_admin', 'Grant: {group}', { group: g.gid }, undefined, { escape: false }), path: '/' + GRANT_DIR + '/' + g.gid })
	}
	const root = roots.length === 1 ? roots[0] : await chooseRoot(roots)
	if (!root) return

	const run = async (op, dest) => {
		const client = davClient()
		const destDir = dest.replace(/\/+$/, '') || '/'
		let done = 0
		for (const node of nodes) {
			const src = node.path
			if (!src || src === destDir || destDir.startsWith(src + '/')) {
				continue // moving into itself / its own subtree
			}
			const dst = (destDir === '/' ? '' : destDir) + '/' + node.basename
			if (src === dst) continue
			try {
				if (op === 'move') {
					await client.moveFile(src, dst, { overwrite: false })
					emit('files:node:deleted', node)
				} else {
					await client.copyFile(src, dst, { overwrite: false })
				}
				done++
			} catch (e) {
				const status = e?.status ?? e?.response?.status
				showError(status === 412
					? t('user_group_admin', '"{name}" already exists in the destination', { name: node.basename }, undefined, { escape: false })
					: status === 507
						? t('user_group_admin', 'Not enough space in the destination grant for "{name}"', { name: node.basename }, undefined, { escape: false })
						: t('user_group_admin', 'Could not {op} "{name}"', { op: op === 'move' ? t('user_group_admin', 'move') : t('user_group_admin', 'copy'), name: node.basename }, undefined, { escape: false }))
			}
		}
		if (done > 0) {
			showSuccess(op === 'move'
				? t('user_group_admin', 'Moved {n} item(s) to {dest}', { n: done, dest: root.label }, undefined, { escape: false })
				: t('user_group_admin', 'Copied {n} item(s) to {dest}', { n: done, dest: root.label }, undefined, { escape: false }))
		}
	}

	const picker = getFilePickerBuilder(t('user_group_admin', 'Destination in {root}', { root: root.label }, undefined, { escape: false }))
		.allowDirectories(true)
		.setMultiSelect(false)
		.startAt(root.path)
		// Folders only; inside Home hide the grants dotfolder (it has its own entry).
		.setFilter((node) => node.type === 'folder' && !(node.basename === GRANT_DIR))
		.setButtonFactory((selected, currentPath) => {
			const dest = (selected.length === 1 && selected[0].type === 'folder') ? selected[0].path : currentPath
			const shown = dest === '/' ? t('user_group_admin', 'Home') : dest.replace(/^\/\.uga_grants\//, '').replace(/^\//, '')
			return [
				{ label: t('user_group_admin', 'Copy to {dest}', { dest: shown }, undefined, { escape: false }), callback: () => run('copy', dest) },
				{ label: t('user_group_admin', 'Move to {dest}', { dest: shown }, undefined, { escape: false }), type: 'primary', callback: () => run('move', dest) },
			]
		})
		.build()
	// The picker's breadcrumb prints raw path segments; show the grants dotfolder
	// under its UI name while the dialog is open.
	const relabel = () => {
		document.querySelectorAll('.file-picker, .file-picker__breadcrumbs, [class*="file-picker"]').forEach((el) => {
			const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT)
			let n
			while ((n = walker.nextNode())) {
				if (n.nodeValue && n.nodeValue.trim() === GRANT_DIR) {
					n.nodeValue = n.nodeValue.replace(GRANT_DIR, t('user_group_admin', 'Grants'))
				}
			}
		})
	}
	const observer = new MutationObserver(relabel)
	observer.observe(document.body, { childList: true, subtree: true, characterData: true })
	try {
		await picker.pick()
	} catch (e) {
		// closed without choosing
	} finally {
		observer.disconnect()
	}
}

/**
 * @param {() => Array<{gid: string}>} getGrantGroups live getter for the user's grant groups
 */
export function registerGrantTransferAction(getGrantGroups) {
	registerFileAction({
		id:            'uga-transfer',
		displayName:   () => t('user_group_admin', 'Move or copy'),
		iconSvgInline: () => MoveSvg,
		order:         15, // where NC's own (hidden) "Move or copy" sits
		enabled: ({ nodes, view }) => {
			if (!nodes.length) return false
			const vid = view?.id ?? ''
			// Everywhere the stock action worked (home, favorites, recent, shares)
			// and inside grants — which the Files router shows under the PARENT
			// 'uga-grants' view with dir /{gid}/…; its top-level synthetic group
			// entries (fileid 0) fail the fileid test below. Not the owner's
			// read-only Sponsored folders.
			if (vid === 'uga-sponsored') return false
			return nodes.every((n) => n.fileid && (n.permissions & Permission.READ) !== 0)
		},
		exec: async ({ nodes }) => {
			await transfer(nodes, getGrantGroups())
			return null
		},
		execBatch: async ({ nodes }) => {
			await transfer(nodes, getGrantGroups())
			return nodes.map(() => null)
		},
	})
}
