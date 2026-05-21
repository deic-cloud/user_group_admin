<template>
	<div class="uga-details">
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
			<h3>{{ t('user_group_admin', 'Members') }}</h3>
			<ul class="uga-member-list">
				<li v-for="m in members" :key="m.uid + m.invitation_email" class="uga-member">
					<span class="uga-member__uid">{{ m.uid === 'uga_external' ? m.invitation_email : m.uid }}</span>
					<span class="uga-member__status">{{ statusLabel(m.status) }}</span>
					<div class="uga-member__actions" v-if="isOwner">
						<NcButton v-if="m.status === 0" size="small" @click="approve(m.uid)">
							{{ t('user_group_admin', 'Approve') }}
						</NcButton>
						<NcButton size="small" variant="error" @click="remove(m.uid)">
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
			<NcTextField v-model="editDescription" :label="t('user_group_admin', 'Description')" />
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
				:input-label="t('user_group_admin', 'Storage grant')" />

			<div class="uga-settings-actions">
				<NcButton variant="primary" @click="saveSettings">{{ t('user_group_admin', 'Save') }}</NcButton>
				<NcButton variant="error" @click="confirmDelete">{{ t('user_group_admin', 'Delete group') }}</NcButton>
			</div>
		</div>

	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps({
	gid:         { type: String, required: true },
	isOwner:     { type: Boolean, default: false },
	currentUser: { type: String, required: true },
})
const emit = defineEmits(['updated', 'deleted'])

const OCS = '/ocs/v2.php/apps/user_group_admin/api/v1'
const QUOTA_OPTIONS = ['1 GB', '5 GB', '10 GB', '20 GB', '50 GB', '100 GB', 'none']

const members          = ref([])
const activeTab        = ref('members')
const inviteUser       = ref(null)
const userOptions      = ref([])
const searchingUsers   = ref(false)
const inviteError      = ref('')
const editDescription  = ref('')
const editOpen         = ref(false)
const editPrivate      = ref(false)
const editStorageGrant = ref('none')
const quotaOptions     = QUOTA_OPTIONS.map(v => ({ id: v, label: v }))

const STATUS_LABELS = {
	[-1]: t('user_group_admin', 'Invited'),
	  0:  t('user_group_admin', 'Requested'),
	  1:  t('user_group_admin', 'Active'),
	  2:  t('user_group_admin', 'Declined'),
}
function statusLabel(s) { return STATUS_LABELS[s] ?? String(s) }

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
	editOpen.value         = !!g.open
	editPrivate.value      = !!g.private
	editStorageGrant.value = g.storage_grant || 'none'
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

async function remove(uid) {
	await axios.delete(`${OCS}/groups/${encodeURIComponent(props.gid)}/members/${encodeURIComponent(uid)}`,
		{ headers: { 'OCS-APIREQUEST': 'true' } })
	loadMembers()
}

async function leaveGroup() {
	if (!confirm(t('user_group_admin', 'Leave this group?'))) return
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
		await axios.put(`${OCS}/groups/${encodeURIComponent(props.gid)}`, {
			description:   editDescription.value,
			open:          editOpen.value,
			private:       editPrivate.value,
			storage_grant: editStorageGrant.value === 'none' ? '' : editStorageGrant.value,
		}, { headers: { 'OCS-APIREQUEST': 'true' } })
		showSuccess(t('user_group_admin', 'Group updated'))
		emit('updated')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to update group'))
	}
}

async function confirmDelete() {
	if (!confirm(t('user_group_admin', 'Delete this group? This cannot be undone.'))) return
	try {
		await axios.delete(`${OCS}/groups/${encodeURIComponent(props.gid)}`,
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		emit('deleted')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to delete group'))
	}
}

onMounted(() => { loadMembers(); if (props.isOwner) loadGroup() })
</script>

<style scoped>
.uga-details { min-height: 300px; }
.uga-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--color-border); margin-bottom: 16px; }
.uga-tabs button { padding: 8px 16px; border: none; background: none; cursor: pointer;
	border-bottom: 2px solid transparent; color: var(--color-text-maxcontrast); }
.uga-tabs button.active { color: var(--color-main-text); border-bottom-color: var(--color-primary-element); }
.uga-member-list { list-style: none; padding: 0; margin: 0 0 16px; }
.uga-member { display: flex; align-items: center; gap: 8px; padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark); }
.uga-member__uid { flex: 1; }
.uga-member__status { font-size: .85em; color: var(--color-text-maxcontrast); }
.uga-member__actions { display: flex; gap: 4px; }
.uga-add-member { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
.uga-add-member :deep(button) { flex-shrink: 0; }
.uga-user-select { flex: 1; min-width: 0; }
.uga-settings-actions { display: flex; gap: 8px; margin-top: 16px; }
.uga-error { color: var(--color-error); }
.uga-leave { margin-top: 24px; }
.uga-hint { font-size: .9em; color: var(--color-text-maxcontrast); margin: 4px 0 8px; }
h3 { font-size: 1em; font-weight: 600; margin: 20px 0 8px; }
</style>
