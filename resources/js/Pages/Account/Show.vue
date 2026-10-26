<template>
  <Layout>
    <div class="max-w-2xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <h1 class="text-3xl font-bold text-gray-900">Account</h1>

      <section class="mt-8 bg-white rounded-lg border shadow-sm p-6">
        <h2 class="text-xl font-semibold text-gray-900">Your data</h2>
        <p class="mt-2 text-gray-600">
          Download a copy of your profile and activity before deleting your account.
        </p>
        <a
          :href="route('account.export')"
          class="inline-flex mt-4 items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        >
          Download your data
        </a>
      </section>
      <section class="mt-6 bg-white rounded-lg border shadow-sm p-6"><h2 class="text-xl font-semibold text-gray-900">Notification emails</h2><label class="block mt-4 text-sm font-medium">General notifications<n-select v-model:value="notificationEmailMode" data-testid="general-notification-preference" class="mt-1" :options="emailOptions" /></label><label v-if="user?.is_admin" class="block mt-4 text-sm font-medium">Admin report alerts<n-select v-model:value="adminReportEmailMode" data-testid="admin-notification-preference" class="mt-1" :options="emailOptions" /></label><div class="mt-6"><n-button type="primary" :loading="savingPreferences" @click="savePreferences">Save preferences</n-button></div></section>

      <section class="mt-6 bg-red-50 rounded-lg border border-red-200 p-6">
        <h2 class="text-xl font-semibold text-red-900">Delete account</h2>
        <p class="mt-2 text-red-800">
          This permanently deletes your account and personal activity. Public definitions and moderation history are retained anonymously.
        </p>
        <div class="mt-[22px]"><n-button type="error" @click="showDeleteModal = true">Delete account</n-button></div>
      </section>
    </div>

    <n-modal v-model:show="showDeleteModal" preset="card" title="Delete your account" class="max-w-lg">
      <form @submit.prevent="deleteAccount">
        <p class="text-gray-700">
          This action is permanent. Type <strong>DELETE</strong> to confirm account deletion.
        </p>
        <n-input v-model:value="confirmation" class="mt-4" :input-props="{ 'aria-label': 'Type DELETE to confirm' }" placeholder="DELETE" />
        <p v-if="confirmationError" class="mt-2 text-sm text-red-600" role="alert">{{ confirmationError }}</p>
        <div class="flex justify-end gap-3 mt-6">
          <n-button @click="closeDeleteModal">Cancel</n-button>
          <n-button type="error" attr-type="submit" :disabled="confirmation !== 'DELETE'" :loading="deleting">
            Permanently delete account
          </n-button>
        </div>
      </form>
    </n-modal>
  </Layout>
</template>

<script setup>
import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import Layout from '@/Components/Layout.vue'

const showDeleteModal = ref(false)
const confirmation = ref('')
const confirmationError = ref('')
const deleting = ref(false)
const user = usePage().props.auth?.user
const notificationEmailMode = ref(user?.notification_email_mode || 'daily')
const adminReportEmailMode = ref(user?.admin_report_email_mode || 'daily')
const savingPreferences = ref(false)
const emailOptions = [{ label: 'Daily digest', value: 'daily' }, { label: 'Immediate', value: 'immediate' }, { label: 'Off', value: 'off' }]
const savePreferences = () => { savingPreferences.value = true; router.patch(route('account.notifications.update'), { notification_email_mode: notificationEmailMode.value, admin_report_email_mode: adminReportEmailMode.value }, { onFinish: () => { savingPreferences.value = false } }) }

const closeDeleteModal = () => {
  showDeleteModal.value = false
  confirmation.value = ''
  confirmationError.value = ''
}

const deleteAccount = () => {
  confirmationError.value = ''
  deleting.value = true

  router.delete(route('account.destroy'), {
    data: { confirmation: confirmation.value },
    onError: (errors) => {
      confirmationError.value = errors.confirmation
    },
    onFinish: () => {
      deleting.value = false
    },
  })
}
</script>
