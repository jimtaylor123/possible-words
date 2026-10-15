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

      <section class="mt-6 bg-red-50 rounded-lg border border-red-200 p-6">
        <h2 class="text-xl font-semibold text-red-900">Delete account</h2>
        <p class="mt-2 text-red-800">
          This permanently deletes your account and personal activity. Public definitions and moderation history are retained anonymously.
        </p>
        <n-button class="mt-6" type="error" @click="showDeleteModal = true">Delete account</n-button>
      </section>
    </div>

    <n-modal v-model:show="showDeleteModal" preset="card" title="Delete your account" class="max-w-lg">
      <form @submit.prevent="deleteAccount">
        <p class="text-gray-700">
          This action is permanent. Type <strong>DELETE</strong> to confirm account deletion.
        </p>
        <n-input v-model:value="confirmation" class="mt-4" aria-label="Type DELETE to confirm" placeholder="DELETE" />
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
import { router } from '@inertiajs/vue3'
import Layout from '@/Components/Layout.vue'

const showDeleteModal = ref(false)
const confirmation = ref('')
const confirmationError = ref('')
const deleting = ref(false)

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
