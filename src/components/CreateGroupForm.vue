<template>
	<form @submit.prevent="submit">
		<NcTextField v-model="gid" :label="t('user_group_admin', 'Group name')" required />
		<NcTextField v-model="description" :label="t('user_group_admin', 'Description (optional)')" />
		<NcCheckboxRadioSwitch v-model="isOpen">
			{{ t('user_group_admin', 'Open group (anyone can join without invitation)') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch v-model="isPrivate">
			{{ t('user_group_admin', 'Private group (hidden from search)') }}
		</NcCheckboxRadioSwitch>
		<p v-if="error" class="uga-error">{{ error }}</p>
		<div class="uga-dialog-actions">
			<NcButton @click="$emit('cancel')">{{ t('user_group_admin', 'Cancel') }}</NcButton>
			<NcButton variant="primary" native-type="submit" :disabled="loading">
				{{ t('user_group_admin', 'Create') }}
			</NcButton>
		</div>
	</form>
</template>

<script setup>
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const emit = defineEmits(['created', 'cancel'])

const OCS = '/ocs/v2.php/apps/user_group_admin/api/v1'

const gid         = ref('')
const description = ref('')
const isOpen      = ref(false)
const isPrivate   = ref(false)
const loading     = ref(false)
const error       = ref('')

async function submit() {
	if (!gid.value.trim()) return
	loading.value = true
	error.value   = ''
	try {
		await axios.post(OCS + '/groups', {
			gid:         gid.value.trim(),
			description: description.value,
			open:        isOpen.value,
			private:     isPrivate.value,
		}, { headers: { 'OCS-APIREQUEST': 'true' } })
		emit('created')
	} catch (e) {
		error.value = e.response?.data?.ocs?.meta?.message ?? t('user_group_admin', 'Failed to create group')
	} finally {
		loading.value = false
	}
}
</script>

<style scoped>
.uga-error { color: var(--color-error); margin-top: .5em; }
.uga-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; margin-bottom: 8px; }
</style>
