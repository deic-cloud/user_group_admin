<template>
	<div class="uga-details">
		<!-- Group identity — the gid is the WebDAV path segment for grant folders; shown to all members -->
		<div class="uga-identity">
			<div class="uga-identity__row">
				<span class="uga-identity__label">{{ t('user_group_admin', 'Group ID') }}:</span>
				<code class="uga-identity__value" :title="t('user_group_admin', 'Used in WebDAV paths for grant folders')">{{ gid }}</code>
			</div>
			<div v-if="groupOwner" class="uga-identity__row">
				<span class="uga-identity__label">{{ t('user_group_admin', 'Owner') }}:</span>
				<span class="uga-identity__desc">{{ ownerLabel }}</span>
			</div>
			<div v-if="groupDescription" class="uga-identity__row">
				<span class="uga-identity__label">{{ t('user_group_admin', 'Description') }}:</span>
				<span class="uga-identity__desc">{{ groupDescription }}</span>
			</div>
		</div>
		<!-- Tab bar -->
		<div class="uga-tabs">
			<button :class="{ active: activeTab === 'members' }" @click="activeTab = 'members'">
				{{ t('user_group_admin', 'Members') }}
			</button>
			<button v-if="isOwner" :class="{ active: activeTab === 'settings' }" @click="activeTab = 'settings'">
				{{ t('user_group_admin', 'Settings') }}
			</button>
		</div>

		<!-- Members tab -->
		<div v-if="activeTab === 'members'">
			<div v-if="pendingOwner === currentUser" class="uga-transfer-offer">
				<p v-if="groupCommitted">{{ t('user_group_admin', 'You have been offered ownership of this group. Accepting makes you responsible for its storage and billing (committed pool: {c}).', { c: groupCommitted }) }}</p>
				<p v-else>{{ t('user_group_admin', 'You have been offered ownership of this group. Accepting makes you responsible for its storage and billing.') }}</p>
				<div class="uga-transfer-offer__actions">
					<NcButton variant="primary" @click="acceptOwnership">{{ t('user_group_admin', 'Accept ownership') }}</NcButton>
					<NcButton @click="declineOwnership">{{ t('user_group_admin', 'Decline') }}</NcButton>
				</div>
			</div>

			<h3>{{ t('user_group_admin', 'Members') }}</h3>
			<ul class="uga-member-list">
				<li v-for="m in members" :key="m.uid + m.invitation_email" class="uga-member" :class="{ 'uga-member--pending': m.status !== 1 }">
					<span class="uga-member__uid">{{ memberLabel(m) }}</span>
					<span class="uga-member__status">{{ memberStatus(m) }}</span>
					<div class="uga-member__actions" v-if="isOwner">
						<NcButton v-if="m.status === 0" size="small" @click="approve(m.uid)">
							{{ t('user_group_admin', 'Approve') }}
						</NcButton>
						<NcButton size="small" variant="error" @click="remove(m.uid, m.invitation_email)">
							{{ t('user_group_admin', 'Remove') }}
						</NcButton>
					</div>
				</li>
			</ul>

			<template v-if="isOwner">
				<h3>{{ t('user_group_admin', 'Add member') }}</h3>
				<div class="uga-add-member">
					<NcSelect
						v-model="inviteUser"
						:options="userOptions"
						:loading="searchingUsers"
						:searchable="true"
						:placeholder="t('user_group_admin', 'Search for user or enter email…')"
						label="label"
						track-by="uid"
						class="uga-user-select"
						@search="onUserSearch" />
					<NcButton :disabled="!inviteUser" @click="inviteByUid">
						{{ t('user_group_admin', 'Invite user') }}
					</NcButton>
					<NcButton :disabled="!isValidEmail(inviteQuery)" @click="inviteByEmail">
						{{ t('user_group_admin', 'Invite via email') }}
					</NcButton>
				</div>
				<p v-if="inviteError" class="uga-error">{{ inviteError }}</p>
			</template>

			<div v-if="!isOwner" class="uga-leave">
				<NcButton variant="error" @click="leaveGroup">
					{{ t('user_group_admin', 'Leave group') }}
				</NcButton>
			</div>
		</div>

		<!-- Settings tab (owner only) -->
		<div v-if="activeTab === 'settings' && isOwner">
			<h3>{{ t('user_group_admin', 'Group settings') }}</h3>
			<NcTextField v-model="editDescription" :label="t('user_group_admin', 'Description')" class="uga-field" />
			<NcCheckboxRadioSwitch v-model="editOpen">
				{{ t('user_group_admin', 'Open group') }}
			</NcCheckboxRadioSwitch>
			<p class="uga-hint">{{ t('user_group_admin', 'Anyone can join without an invitation.') }}</p>
			<NcCheckboxRadioSwitch v-model="editPrivate">
				{{ t('user_group_admin', 'Private group') }}
			</NcCheckboxRadioSwitch>
			<p class="uga-hint">{{ t('user_group_admin', 'Hidden from search; only members and invited users can see it.') }}</p>

			<h3>{{ t('user_group_admin', 'Storage grant') }}</h3>
			<p class="uga-hint">
				{{ t('user_group_admin', 'Allocate storage from your own quota to group members.') }}
			</p>
			<NcSelect v-model="editStorageGrant"
				:options="quotaOptions"
				:input-label="t('user_group_admin', 'Per-member quota')" />
			<NcSelect v-model="editStorageGrantTotal"
				:options="quotaTotalOptions"
				:input-label="t('user_group_admin', 'Total quota (all members)')" />

			<NcCheckboxRadioSwitch v-model="editGrantSyncHide">
				{{ t('user_group_admin', 'Hide grant folder from sync clients') }}
			</NcCheckboxRadioSwitch>
			<p class="uga-hint">{{ t('user_group_admin', 'When checked, desktop sync clients cannot see or sync the grant folder. Members see a locked entry in their folder visibility settings.') }}</p>

			<h3>{{ t('user_group_admin', 'Home-directory top-up') }}</h3>
			<p class="uga-hint">
				{{ t('user_group_admin', 'Allocate extra free quota on your members\' own home directories, drawn from your own quota. Independent of the grant folder above.') }}
			</p>
			<NcTextField v-model="editTopup"
				:label="t('user_group_admin', 'Per-member home top-up (e.g. 100 GB, empty to remove)')" />

			<h3>{{ t('user_group_admin', 'Transfer ownership') }}</h3>
			<p class="uga-hint">
				{{ t('user_group_admin', 'Hand this group — and its storage billing — to another active member. They must accept before it takes effect.') }}
			</p>
			<p v-if="pendingOwner" class="uga-hint uga-pending">
				{{ t('user_group_admin', 'Offer pending with {user}, awaiting their acceptance.', { user: pendingOwner }) }}
			</p>
			<div class="uga-add-member">
				<NcSelect v-model="transferUser"
					:options="transferOptions"
					:searchable="true"
					:placeholder="t('user_group_admin', 'Choose the new owner…')"
					label="label"
					track-by="uid"
					class="uga-user-select" />
				<NcButton :disabled="!transferUser" @click="initiateTransfer">
					{{ t('user_group_admin', 'Transfer ownership') }}
				</NcButton>
			</div>
			<p v-if="transferError" class="uga-error">{{ transferError }}</p>

			<div class="uga-settings-actions">
				<NcButton variant="primary" @click="saveSettings">{{ t('user_group_admin', 'Save') }}</NcButton>
				<NcButton variant="error" @click="confirmDelete">{{ t('user_group_admin', 'Delete group') }}</NcButton>
			</div>
		</div>

		<NcDialog v-if="confirmDialog"
			:name="confirmDialog.title"
			@closing="confirmResolve(false)">
			<p class="uga-confirm">{{ confirmDialog.message }}</p>
			<template #actions>
				<NcButton @click="confirmResolve(false)">{{ t('user_group_admin', 'Cancel') }}</NcButton>
				<NcButton :variant="confirmDialog.variant || 'primary'" @click="confirmResolve(true)">{{ confirmDialog.confirmLabel }}</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps({
	gid:         { type: String, required: true },
	isOwner:     { type: Boolean, default: false },
	currentUser: { type: String, required: true },
})
const emit = defineEmits(['updated', 'deleted'])

// Native (NcDialog) confirmation — replaces window.confirm. askConfirm() resolves
// true/false so callers keep their `if (!await askConfirm(...)) return` flow.
const confirmDialog = ref(null)
function askConfirm(opts) {
	return new Promise(resolve => { confirmDialog.value = { ...opts, resolve } })
}
function confirmResolve(val) {
	const d = confirmDialog.value
	confirmDialog.value = null
	d?.resolve(val)
}

const OCS = '/ocs/v2.php/apps/user_group_admin/api/v1'
const FA_OCS = '/ocs/v2.php/apps/files_accounting/api/v1'
const QUOTA_OPTIONS       = ['1 GB', '5 GB', '10 GB', '20 GB', '50 GB', '100 GB', 'none']
const QUOTA_TOTAL_OPTIONS = ['10 GB', '50 GB', '100 GB', '250 GB', '500 GB', '1 TB', 'none']

const members               = ref([])
const activeTab             = ref('members')
const inviteUser            = ref(null)
const userOptions           = ref([])
const searchingUsers        = ref(false)
const inviteError           = ref('')
const editDescription       = ref('')
const groupDescription      = ref('')
const groupOwner            = ref('')
const groupOwnerName        = ref('')
const editOpen              = ref(false)
const editPrivate           = ref(false)
const editStorageGrant      = ref('none')
const editStorageGrantTotal = ref('none')
const editGrantSyncHide     = ref(true)
const editTopup             = ref('')
const pendingOwner          = ref('')
const transferUser          = ref(null)
const transferError         = ref('')
const groupCommitted        = ref('')
const quotaOptions          = QUOTA_OPTIONS.map(v => ({ id: v, label: v }))
const quotaTotalOptions     = QUOTA_TOTAL_OPTIONS.map(v => ({ id: v, label: v }))

// Active members (excluding external invites and the current user) — candidates
// to receive ownership.
const transferOptions = computed(() => members.value
	.filter(m => m.status === 1 && !m.invitation_email && m.uid !== props.currentUser)
	.map(m => ({ uid: m.uid, label: m.uid })))

const ownerLabel = computed(() => (groupOwnerName.value && groupOwnerName.value !== groupOwner.value)
	? `${groupOwnerName.value} (${groupOwner.value})`
	: groupOwner.value)

const STATUS_LABELS = {
	[-1]: t('user_group_admin', 'Invited'),
	  0:  t('user_group_admin', 'Requested'),
	  1:  t('user_group_admin', 'Active'),
	  2:  t('user_group_admin', 'Declined'),
}
function statusLabel(s) { return STATUS_LABELS[s] ?? String(s) }
function memberStatus(m) {
	if (m.invitation_email && m.status === -1) {
		return t('user_group_admin', 'Email invitation sent')
	}
	return statusLabel(m.status)
}
function memberLabel(m) {
	if (m.invitation_email && m.status !== 1) { return m.invitation_email }
	return m.display_name && m.display_name !== m.uid ? `${m.display_name} (${m.uid})` : m.uid
}

async function loadMembers() {
	const { data } = await axios.get(`${OCS}/groups/${encodeURIComponent(props.gid)}/members`,
		{ headers: { 'OCS-APIREQUEST': 'true' } })
	members.value = data.ocs?.data ?? []
}

async function loadGroup() {
	const { data } = await axios.get(`${OCS}/groups/${encodeURIComponent(props.gid)}`,
		{ headers: { 'OCS-APIREQUEST': 'true' } })
	const g = data.ocs?.data ?? {}
	editDescription.value  = g.description ?? ''
	groupDescription.value = g.description ?? ''
	groupOwner.value = g.owner ?? ''
	groupOwnerName.value = g.owner_display_name ?? ''
	editOpen.value         = !!g.open
	editPrivate.value      = !!g.private
	const grantId = g.storage_grant || 'none'
	editStorageGrant.value = quotaOptions.find(o => o.id === grantId) ?? quotaOptions.at(-1)
	const totalId = g.storage_grant_total || 'none'
	editStorageGrantTotal.value = quotaTotalOptions.find(o => o.id === totalId) ?? quotaTotalOptions.at(-1)
	editGrantSyncHide.value = g.grant_sync_hide !== false
	pendingOwner.value      = g.pending_owner ?? ''
	groupCommitted.value    = g.storage_grant_total ?? ''

	// Home-directory top-up lives in files_accounting (optional app) and is
	// owner-only there — shown only in the owner Settings tab, so skip it for
	// non-owners (who would otherwise get a 403).
	if (props.isOwner) {
		try {
			const { data: tu } = await axios.get(`${FA_OCS}/grouptopup`,
				{ params: { gid: props.gid }, headers: { 'OCS-APIREQUEST': 'true' } })
			editTopup.value = (tu.ocs?.data?.bytes > 0) ? (tu.ocs.data.human ?? '') : ''
		} catch (e) { /* files_accounting unavailable — leave top-up blank */ }
	}
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const inviteQuery = ref('')

function isValidEmail(v) { return EMAIL_RE.test(v ?? '') }

async function onUserSearch(query) {
	inviteQuery.value = query ?? ''
	if (!query || query.length < 2) { userOptions.value = []; return }
	searchingUsers.value = true
	try {
		const { data } = await axios.get(`${OCS}/users/search`, {
			params: { q: query },
			headers: { 'OCS-APIREQUEST': 'true' },
		})
		userOptions.value = (data.ocs?.data ?? []).map(u => ({
			uid:   u.uid,
			label: u.displayName ? `${u.displayName} (${u.uid})` : u.uid,
		}))
	} catch (e) {
		userOptions.value = []
	} finally {
		searchingUsers.value = false
	}
}

function resetInviteForm() {
	inviteUser.value  = null
	userOptions.value = []
	inviteQuery.value = ''
	inviteError.value = ''
}

async function inviteByUid() {
	if (!inviteUser.value) return
	inviteError.value = ''
	try {
		await axios.post(
			`${OCS}/groups/${encodeURIComponent(props.gid)}/members`,
			{ uid: inviteUser.value.uid },
			{ headers: { 'OCS-APIREQUEST': 'true' } },
		)
		resetInviteForm()
		loadMembers()
	} catch (e) {
		inviteError.value = e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Invitation failed')
	}
}

async function inviteByEmail() {
	if (!isValidEmail(inviteQuery.value)) return
	inviteError.value = ''
	try {
		await axios.post(
			`${OCS}/groups/${encodeURIComponent(props.gid)}/members/external`,
			{ email: inviteQuery.value },
			{ headers: { 'OCS-APIREQUEST': 'true' } },
		)
		resetInviteForm()
		loadMembers()
	} catch (e) {
		inviteError.value = e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Invitation failed')
	}
}

async function approve(uid) {
	await axios.put(`${OCS}/groups/${encodeURIComponent(props.gid)}/members/${encodeURIComponent(uid)}`,
		{}, { headers: { 'OCS-APIREQUEST': 'true' } })
	loadMembers()
}

async function remove(uid, email = '') {
	await axios.delete(`${OCS}/groups/${encodeURIComponent(props.gid)}/members/${encodeURIComponent(uid)}`,
		{ params: email ? { email } : {}, headers: { 'OCS-APIREQUEST': 'true' } })
	loadMembers()
}

async function leaveGroup() {
	if (!await askConfirm({
		title: t('user_group_admin', 'Leave group'),
		message: t('user_group_admin', 'Leave this group?'),
		confirmLabel: t('user_group_admin', 'Leave'),
		variant: 'error',
	})) return
	try {
		await axios.delete(
			`${OCS}/groups/${encodeURIComponent(props.gid)}/members/${encodeURIComponent(props.currentUser)}`,
			{ headers: { 'OCS-APIREQUEST': 'true' } },
		)
		emit('deleted')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to leave group'))
	}
}

async function saveSettings() {
	try {
		const grantVal      = editStorageGrant.value?.id      ?? editStorageGrant.value
		const grantTotalVal = editStorageGrantTotal.value?.id ?? editStorageGrantTotal.value
		await axios.put(`${OCS}/groups/${encodeURIComponent(props.gid)}`, {
			description:         editDescription.value,
			open:                editOpen.value,
			private:             editPrivate.value,
			storage_grant:       grantVal      === 'none' ? '' : (grantVal ?? ''),
			storage_grant_total: grantTotalVal === 'none' ? '' : (grantTotalVal ?? ''),
			grant_sync_hide:     editGrantSyncHide.value,
		}, { headers: { 'OCS-APIREQUEST': 'true' } })
		// Save the home-directory top-up via files_accounting (owner self-service).
		try {
			await axios.post(`${FA_OCS}/grouptopup`,
				{ gid: props.gid, quota: (editTopup.value || '').trim() || '0' },
				{ headers: { 'OCS-APIREQUEST': 'true' } })
		} catch (e) { /* files_accounting unavailable — group settings still saved */ }
		groupDescription.value = editDescription.value
		showSuccess(t('user_group_admin', 'Group updated'))
		emit('updated')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to update group'))
	}
}

async function confirmDelete() {
	if (!await askConfirm({
		title: t('user_group_admin', 'Delete group'),
		message: t('user_group_admin', 'Delete this group? This cannot be undone.'),
		confirmLabel: t('user_group_admin', 'Delete'),
		variant: 'error',
	})) return
	try {
		await axios.delete(`${OCS}/groups/${encodeURIComponent(props.gid)}`,
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		emit('deleted')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to delete group'))
	}
}

async function initiateTransfer() {
	if (!transferUser.value) return
	transferError.value = ''
	try {
		const { data } = await axios.put(`${OCS}/groups/${encodeURIComponent(props.gid)}/owner`,
			{ uid: transferUser.value.uid },
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		const warning = data.ocs?.data?.warning
		if (warning) {
			showWarning(warning)
		} else {
			showSuccess(t('user_group_admin', 'Ownership offer sent — awaiting acceptance'))
		}
		transferUser.value = null
		loadGroup()
	} catch (e) {
		transferError.value = e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Transfer failed')
	}
}

async function acceptOwnership() {
	try {
		await axios.put(`${OCS}/groups/${encodeURIComponent(props.gid)}/owner/pending`,
			{}, { headers: { 'OCS-APIREQUEST': 'true' } })
		showSuccess(t('user_group_admin', 'You are now the owner of this group'))
		pendingOwner.value = ''
		emit('updated')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to accept ownership'))
	}
}

async function declineOwnership() {
	try {
		await axios.delete(`${OCS}/groups/${encodeURIComponent(props.gid)}/owner/pending`,
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		showSuccess(t('user_group_admin', 'Ownership offer declined'))
		pendingOwner.value = ''
		emit('updated')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to decline'))
	}
}

onMounted(() => { loadMembers(); loadGroup() })
</script>

<style scoped>
/* padding-bottom gives the last row (Save / Delete) clearance inside the dialog's
   scroll area — without it the buttons are clipped at the bottom on shorter windows. */
.uga-details { min-height: 300px; padding-bottom: 40px; }
.uga-identity { margin-bottom: 16px; }
.uga-identity__row { display: flex; align-items: baseline; gap: 8px; margin: 2px 0; }
.uga-identity__label { color: var(--color-text-maxcontrast); font-size: .9em; min-width: 6em; }
.uga-identity__value { font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark); padding: 1px 6px;
	border-radius: var(--border-radius, 4px); user-select: all; }
.uga-identity__desc { word-break: break-word; }
.uga-field { margin: 20px 0 20px; }
.uga-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--color-border); margin-bottom: 16px; }
.uga-tabs button { padding: 8px 16px; border: none; background: none; cursor: pointer;
	border-bottom: 2px solid transparent; color: var(--color-text-maxcontrast); }
.uga-tabs button.active { color: var(--color-main-text); border-bottom-color: var(--color-primary-element); }
.uga-member-list { list-style: none; padding: 0; margin: 0 0 16px; }
.uga-member { display: flex; align-items: center; gap: 8px; padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark); }
.uga-member__uid { flex: 1; }
.uga-member__status { font-size: .85em; color: var(--color-text-maxcontrast); }
.uga-member--pending .uga-member__uid { font-style: italic; color: var(--color-text-maxcontrast); }
.uga-member__actions { display: flex; gap: 4px; }
.uga-add-member { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
.uga-add-member :deep(button) { flex-shrink: 0; }
.uga-user-select { flex: 1; min-width: 0; }
.uga-settings-actions { display: flex; gap: 8px; margin-top: 16px; }
.uga-error { color: var(--color-error); }
.uga-leave { margin-top: 24px; }
.uga-hint { font-size: .9em; color: var(--color-text-maxcontrast); margin: 4px 0 8px; }
.uga-pending { font-style: italic; }
.uga-transfer-offer { border: 1px solid var(--color-primary-element); background: var(--color-primary-element-light);
	border-radius: var(--border-radius-large, 8px); padding: 12px 16px; margin-bottom: 16px; }
.uga-transfer-offer p { margin: 0 0 8px; }
.uga-transfer-offer__actions { display: flex; gap: 8px; }
h3 { font-size: 1em; font-weight: 600; margin: 20px 0 8px; }
.uga-confirm { margin: 4px 0 8px; }
</style>
