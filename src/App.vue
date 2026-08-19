<template>
	<NcContent app-name="user_group_admin">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew
					:text="t('user_group_admin', 'New group')"
					@click="startCreate">
					<template #icon><PlusIcon :size="20" /></template>
				</NcAppNavigationNew>
				<template v-if="pendingInvitations.length">
					<NcAppNavigationCaption :name="t('user_group_admin', 'Pending invitations')" />
					<NcAppNavigationItem
						v-for="g in pendingInvitations" :key="'inv-' + g.gid"
						:name="g.gid"
						:title="g.description || g.gid"
						:active="selectedGid === g.gid"
						@click="selectInvitation(g)">
						<template #actions>
							<NcActionButton @click="acceptInvitation(g.gid)">
								<template #icon><JoinIcon :size="20" /></template>
								{{ t('user_group_admin', 'Accept') }}
							</NcActionButton>
							<NcActionButton @click="declineInvitation(g.gid)">
								<template #icon><ExitIcon :size="20" /></template>
								{{ t('user_group_admin', 'Decline') }}
							</NcActionButton>
						</template>
					</NcAppNavigationItem>
				</template>
				<NcAppNavigationCaption v-if="myGroups.length" :name="t('user_group_admin', 'My groups')" />
				<NcAppNavigationItem
					v-for="g in myGroups" :key="g.gid"
					:name="g.gid"
					:title="g.description || g.gid"
					:active="selectedGid === g.gid"
					@click="select(g.gid)">
					<template #actions>
						<NcActionButton v-if="g.owner !== currentUser" @click="leave(g.gid)">
							<template #icon><ExitIcon :size="20" /></template>
							{{ t('user_group_admin', 'Leave') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
				<template v-if="joinableGroups.length">
					<NcAppNavigationCaption :name="t('user_group_admin', 'Groups you can join')" />
					<NcAppNavigationItem
						v-for="g in joinableGroups" :key="'j-' + g.gid"
						:name="joinableName(g)"
						:title="g.description || g.gid"
						@click="confirmJoin(g)">
						<template #actions>
							<NcActionButton @click="confirmJoin(g)">
								<template #icon><JoinIcon :size="20" /></template>
								{{ t('user_group_admin', 'Join') }}
							</NcActionButton>
						</template>
					</NcAppNavigationItem>
				</template>
			</template>
			<template #search>
				<NcAppNavigationSearch
					v-model="searchQuery"
					:label="t('user_group_admin', 'Search groups to join')" />
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div class="uga-main">
				<CreateGroupForm v-if="creating"
					@created="onCreated"
					@cancel="creating = false" />
				<GroupDetails v-else-if="selectedGid"
					:key="selectedGid"
					:gid="selectedGid"
					:is-owner="isOwnerOf(selectedGid)"
					:current-user="currentUser"
					:invited="selectedInvited"
					@updated="loadMyGroups"
					@deleted="onDeleted"
					@accept="onInviteAccept"
					@decline="onInviteDecline" />
				<NcEmptyContent v-else
					:name="t('user_group_admin', 'No group selected')"
					:description="t('user_group_admin', 'Select a group from the list, or create a new one.')">
					<template #icon><GroupIcon /></template>
				</NcEmptyContent>
			</div>
		</NcAppContent>
		<NcDialog v-if="joinCandidate"
			:name="t('user_group_admin', 'Join group')"
			@closing="joinCandidate = null">
			<div class="uga-join">
				<p class="uga-join__gid"><code>{{ joinCandidate.gid }}</code></p>
				<p>{{ t('user_group_admin', 'Owner') }}: {{ ownerLabelOf(joinCandidate) }}</p>
				<p v-if="joinCandidate.description" class="uga-join__desc">{{ joinCandidate.description }}</p>
			</div>
			<template #actions>
				<NcButton @click="joinCandidate = null">{{ t('user_group_admin', 'Cancel') }}</NcButton>
				<NcButton variant="primary" @click="doJoin">{{ t('user_group_admin', 'Join') }}</NcButton>
			</template>
		</NcDialog>
	</NcContent>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { mdiPlus, mdiExitToApp, mdiAccountPlus } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import GroupDetails from './components/GroupDetails.vue'
import CreateGroupForm from './components/CreateGroupForm.vue'
import GroupIcon from './components/icons/GroupIcon.vue'

const OCS = '/ocs/v2.php/apps/user_group_admin/api/v1'
const currentUser = getCurrentUser()?.uid ?? ''

const myGroups          = ref([])
const pendingInvitations = ref([])
const joinableGroups    = ref([])
const selectedGid       = ref(null)
const selectedInvited   = ref(false)
const creating          = ref(false)
const searchQuery       = ref('')
const joinCandidate     = ref(null)

// Inline icon components using NcIconSvgWrapper
const PlusIcon  = { props: ['size'], render(h) { return h(NcIconSvgWrapper, { props: { path: mdiPlus } }) } }
const ExitIcon  = { props: ['size'], render(h) { return h(NcIconSvgWrapper, { props: { path: mdiExitToApp } }) } }
const JoinIcon  = { props: ['size'], render(h) { return h(NcIconSvgWrapper, { props: { path: mdiAccountPlus } }) } }

async function loadMyGroups() {
	try {
		const [groupsRes, invitationsRes] = await Promise.all([
			axios.get(OCS + '/groups',      { headers: { 'OCS-APIREQUEST': 'true' } }),
			axios.get(OCS + '/invitations', { headers: { 'OCS-APIREQUEST': 'true' } }),
		])
		myGroups.value = groupsRes.data.ocs?.data ?? []
		pendingInvitations.value = invitationsRes.data.ocs?.data ?? []
		if (selectedGid.value && !myGroups.value.find(g => g.gid === selectedGid.value)) {
			selectedGid.value = null
		}
	} catch (e) {
		showError(t('user_group_admin', 'Failed to load groups'))
	}
}

async function acceptInvitation(gid) {
	try {
		await axios.put(`${OCS}/groups/${encodeURIComponent(gid)}/members/${encodeURIComponent(currentUser)}`,
			{}, { headers: { 'OCS-APIREQUEST': 'true' } })
		await loadMyGroups()
		selectedGid.value = gid
		selectedInvited.value = false // now a member — flip GroupDetails to the normal (member) view
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to accept invitation'))
	}
}

async function declineInvitation(gid) {
	try {
		await axios.delete(`${OCS}/groups/${encodeURIComponent(gid)}/members/${encodeURIComponent(currentUser)}`,
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		await loadMyGroups()
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to decline invitation'))
	}
}

watch(searchQuery, searchJoinable)

async function searchJoinable() {
	if (searchQuery.value.length < 2) {
		joinableGroups.value = []
		return
	}
	try {
		const { data } = await axios.get(OCS + '/groups/search', {
			params: { q: searchQuery.value },
			headers: { 'OCS-APIREQUEST': 'true' },
		})
		joinableGroups.value = data.ocs?.data ?? []
	} catch (e) {}
}

async function join(gid) {
	try {
		await axios.post(`${OCS}/groups/${encodeURIComponent(gid)}/members`,
			{ uid: currentUser }, { headers: { 'OCS-APIREQUEST': 'true' } })
		searchQuery.value = ''
		joinableGroups.value = []
		await loadMyGroups()
		selectedGid.value = gid
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to join group'))
	}
}

function joinableName(g) {
	const dn = g.owner_display_name
	const owner = dn && dn !== g.owner ? `${g.owner}, ${dn}` : g.owner
	return `${g.gid} (${t('user_group_admin', 'owner: {owner}', { owner })})`
}

function ownerLabelOf(g) {
	const dn = g.owner_display_name
	return dn && dn !== g.owner ? `${dn} (${g.owner})` : g.owner
}

// Open a native confirm dialog for a joinable group (owner + description shown).
function confirmJoin(g) {
	joinCandidate.value = g
}

function doJoin() {
	const g = joinCandidate.value
	joinCandidate.value = null
	if (g) { join(g.gid) }
}

async function leave(gid) {
	try {
		await axios.delete(`${OCS}/groups/${encodeURIComponent(gid)}/members/${encodeURIComponent(currentUser)}`,
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		if (selectedGid.value === gid) selectedGid.value = null
		await loadMyGroups()
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to leave group'))
	}
}

function select(gid) {
	creating.value = false
	selectedInvited.value = false
	selectedGid.value = gid
}

// Show a pending invitation's group on the right (identity + members, read-only)
// with Accept / Decline — instead of the nav item being an inert pointer.
function selectInvitation(g) {
	creating.value = false
	selectedGid.value = g.gid
	selectedInvited.value = true
}

function onInviteAccept() {
	acceptInvitation(selectedGid.value)
}

async function onInviteDecline() {
	const gid = selectedGid.value
	await declineInvitation(gid)
	selectedGid.value = null
	selectedInvited.value = false
}

function startCreate() {
	selectedGid.value = null
	creating.value = true
}

function isOwnerOf(gid) {
	return myGroups.value.find(g => g.gid === gid)?.owner === currentUser
}

function onCreated() {
	creating.value = false
	loadMyGroups()
}

function onDeleted() {
	selectedGid.value = null
	loadMyGroups()
}

onMounted(loadMyGroups)
</script>

<style scoped>
.uga-main {
	/* Extra top padding so content (e.g. the create-group form's first field)
	   clears the floating app-navigation toggle in the top-left corner. */
	padding: 24px;
	padding-top: calc(var(--default-clickable-area, 44px) + 8px);
	max-width: 860px;
}
.uga-join { display: flex; flex-direction: column; gap: 6px; padding: 4px 0 8px; }
.uga-join__gid code { font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark); padding: 1px 6px; border-radius: var(--border-radius, 4px); }
.uga-join__desc { color: var(--color-text-maxcontrast); }
</style>
