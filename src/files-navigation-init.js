// Loaded via Util::addInitScript — runs before files-init.js mounts Vue.
//
// Two problems this guards against on a cold load / hard reload of a grant URL:
//  1. FilesNavigationListItem.vue (NC core) reads activeViewId.value.id during
//     the initial render; if the route's view id ('uga-grants'/'uga-grant-*')
//     isn't registered yet, activeViewId is undefined and Vue throws.
//  2. Member grant nodes ride the standard DAV tree (root=/files/{uid} so OCS
//     shares/tags resolve), so navigating into a grant subfolder drifts the URL
//     to the standard 'files' view with a raw '/.uga_grants/{gid}/…' dir. On a
//     hard reload that raw path renders ("All files > .uga_grants > …" leak).
//
// Fix: detect EITHER form (grant view in the path, OR a raw grant path in the
// dir of any view), compute the clean grant target dir '/{gid}/{sub}', park the
// URL on the always-present 'files' root so nothing raw renders, and stash the
// target for files-navigation.js to restore once the router is ready.
// NC keeps the last-active file's id in the path (/apps/files/<view>/<fileid>).
// For a grant folder that fileid resolves — SERVER-SIDE, on reload, before any
// JS runs — to the file's real /.uga_grants/… location and 302-redirects out
// of the grant view (the "reload jumps into a subfolder" bug). Patch the
// History API (before NC's router is created) to strip a trailing /{fileid}
// from grant URLs, so the address bar never carries a fileid for a reload to
// resolve. Scoped to grant URLs only; standard files navigation is untouched.
;(function ugaStripGrantFileid() {
	function clean(url) {
		try {
			var u = new URL(url, window.location.origin)
			var dir = u.searchParams.get('dir') || ''
			var grant = /\/apps\/files\/uga-grant/.test(u.pathname)
				|| /^\/(?:\.uga_grants|Grants)(\/|$)/.test(dir)
			var m = u.pathname.match(/^(.*\/apps\/files\/[^/]+)\/\d+$/)
			if (grant && m) {
				u.pathname = m[1]
				return u.pathname + u.search + u.hash
			}
		} catch (e) { /* ignore */ }
		return url
	}
	['pushState', 'replaceState'].forEach(function (fn) {
		var orig = history[fn].bind(history)
		history[fn] = function (state, title, url) {
			return orig(state, title, (url === undefined || url === null) ? url : clean(url))
		}
	})
})()

;(function ugaNormalizeGrantUrl() {
	try {
		var path = window.location.pathname
		var dir = new URLSearchParams(window.location.search).get('dir') || '/'
		var grantView = path.match(/\/apps\/files\/(uga-grant[^/?#]*)/)
		var rawDir = dir.match(/^\/(?:\.uga_grants|Grants)\/?(.*)$/)
		if (!grantView && !rawDir) return

		var targetDir
		if (rawDir) {
			// Raw grant path leaked into the dir (drifted 'files' view, or a grant
			// view whose dir picked up the prefix): strip it → /{gid}/{sub}.
			targetDir = '/' + (rawDir[1] || '')
		} else {
			var view = decodeURIComponent(grantView[1])
			if (view === 'uga-grants') {
				targetDir = dir
			} else {
				// Child uga-grant-{gid} view: dir is the subpath; prepend the gid.
				var gid = view.replace(/^uga-grant-/, '')
				targetDir = dir === '/' ? '/' + gid : '/' + gid + dir
			}
		}
		if (targetDir.length > 1) targetDir = targetDir.replace(/\/+$/, '')

		sessionStorage.setItem('__uga_pending', JSON.stringify({ view: 'uga-grants', dir: targetDir || '/' }))
		// Park on the bare 'files' route. Use [^?#]* (not [^/?#]*) so any trailing
		// /{fileid} segment is stripped too — a stale fileid left in the URL (e.g.
		// after navigating up) makes NC resolve that file in the standard tree and
		// reopen it, overriding our dir-based restore. We navigate by dir only.
		history.replaceState(null, '', path.replace(/\/apps\/files\/[^?#]*/, '/apps/files/files'))
	} catch (e) { /* ignore */ }
})()
