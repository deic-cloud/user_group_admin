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
						:name="g.description || g.gid"
						:title="g.gid">
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
					:name="g.description || g.gid"
					:title="g.gid"
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
						:name="g.description || g.gid"
						:title="g.gid">
						<template #actions>
							<NcActionButton @click="join(g.gid)">
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
					@updated="loadMyGroups"
					@deleted="onDeleted" />
				<NcEmptyContent v-else
					:name="t('user_group_admin', 'No group selected')"
					:description="t('user_group_admin', 'Select a group from the list, or create a new one.')">
					<template #icon><GroupIcon /></template>
				</NcEmptyContent>
			</div>
		</NcAppContent>
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
import NcContent from '@nextcloud/vue/components/NcContent'
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
const creating          = ref(false)
const searchQuery       = ref('')

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
	selectedGid.value = gid
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
	padding: 24px;
	max-width: 860px;
}
</style>
