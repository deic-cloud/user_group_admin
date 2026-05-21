import { getCSPNonce } from '@nextcloud/auth'
import { t } from '@nextcloud/l10n'
import Vue from 'vue'
import App from './App.vue'

__webpack_nonce__ = getCSPNonce()
Vue.prototype.t = t

new Vue({ render: h => h(App) }).$mount('#app-user-group-admin')
