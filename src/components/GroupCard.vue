<template>
	<div class="uga-card" :class="{ 'uga-card--owned': isOwner }">
		<div class="uga-card__header">
			<strong class="uga-card__name">{{ group.gid }}</strong>
			<span v-if="isOwner" class="uga-card__badge">{{ t('user_group_admin', 'Owner') }}</span>
			<span v-if="group.open" class="uga-card__badge uga-card__badge--open">{{ t('user_group_admin', 'Open') }}</span>
		</div>
		<p v-if="group.description" class="uga-card__desc">{{ group.description }}</p>

		<div class="uga-card__actions">
			<NcButton v-if="joinable" size="small" @click="joinGroup">
				{{ t('user_group_admin', 'Join') }}
			</NcButton>
			<NcButton v-if="isOwner || isMember" size="small" @click="showDetails = true">
				{{ t('user_group_admin', 'Manage') }}
			</NcButton>
			<NcButton v-if="isMember && !isOwner" size="small" variant="error" @click="leaveGroup">
				{{ t('user_group_admin', 'Leave') }}
			</NcButton>
		</div>

		<NcDialog v-if="showDetails"
			:name="group.gid"
			size="large"
			@closing="showDetails = false">
			<GroupDetails :gid="group.gid" :is-owner="isOwner"
				:current-user="currentUser"
				@updated="$emit('updated')"
				@deleted="showDetails = false; $emit('deleted')" />
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import GroupDetails from './GroupDetails.vue'

const props = defineProps({
	group:       { type: Object, required: true },
	currentUser: { type: String, required: true },
	joinable:    { type: Boolean, default: false },
})
const emit = defineEmits(['updated', 'deleted'])

const OCS = '/ocs/v2.php/apps/user_group_admin/api/v1'

const showDetails = ref(false)
const isOwner = computed(() => props.group.owner === props.currentUser)
const isMember = computed(() => !props.joinable)

async function joinGroup() {
	try {
		await axios.post(`${OCS}/groups/${encodeURIComponent(props.group.gid)}/members`,
			{ uid: props.currentUser },
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		emit('updated')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to join group'))
	}
}

async function leaveGroup() {
	try {
		await axios.delete(`${OCS}/groups/${encodeURIComponent(props.group.gid)}/members/${encodeURIComponent(props.currentUser)}`,
			{ headers: { 'OCS-APIREQUEST': 'true' } })
		emit('updated')
	} catch (e) {
		showError(e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to leave group'))
	}
}
</script>

<style scoped>
.uga-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	background: var(--color-main-background);
}
.uga-card--owned { border-color: var(--color-primary-element); }
.uga-card__header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
.uga-card__name { font-size: 1em; }
.uga-card__badge {
	font-size: .75em; padding: 2px 8px;
	border-radius: 10px; background: var(--color-primary-element); color: #fff;
}
.uga-card__badge--open { background: var(--color-success); }
.uga-card__desc { font-size: .9em; color: var(--color-text-maxcontrast); margin: 4px 0 8px; }
.uga-card__actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
</style>
