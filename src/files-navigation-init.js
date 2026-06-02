// Loaded via Util::addInitScript — runs before files-init.js mounts Vue.
// FilesNavigationListItem.vue (NC core) accesses activeViewId.value.id during
// the initial render.  If the route's view id ('uga-grants' / 'uga-grant-*')
// is not yet registered, activeViewId.value is undefined and Vue throws a
// TypeError.  We sidestep this by temporarily replacing the URL with the
// always-registered 'files' view.  files-navigation.js restores the correct
// URL after it has registered all grant views.
;(function ugaPreventNavigationCrash() {
	try {
		var m = window.location.pathname.match(/\/apps\/files\/(uga-grant[^/?#]*)/)
		if (!m) return
		var view = decodeURIComponent(m[1])
		var dir  = new URLSearchParams(window.location.search).get('dir') || '/'
		sessionStorage.setItem('__uga_pending', JSON.stringify({ view: view, dir: dir }))
		var newPath = window.location.pathname.replace(
			/\/apps\/files\/uga-grant[^/?#]*/,
			'/apps/files/files'
		)
		history.replaceState(null, '', newPath)
	} catch (e) { /* ignore */ }
})()
