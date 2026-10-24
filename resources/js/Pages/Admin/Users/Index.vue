<template>
  <Layout>
    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
      <h1 class="mb-2 text-3xl font-bold text-gray-900">User management</h1>
      <p class="mb-6 text-gray-600">Inspect account activity and manage account access.</p>
      <p v-if="$page.props.flash?.success" role="status" class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-green-800">{{ $page.props.flash.success }}</p>
      <p v-if="$page.props.flash?.error" role="alert" class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-red-800">{{ $page.props.flash.error }}</p>

      <form class="mb-6 grid gap-3 sm:grid-cols-[1fr_12rem_8rem_auto]" @submit.prevent="applyFilters">
        <n-input v-model:value="search" aria-label="Search users" placeholder="Search name or email" />
        <n-select v-model:value="sort" aria-label="Sort users" :options="sortOptions" />
        <n-select v-model:value="direction" aria-label="Sort direction" :options="directionOptions" />
        <n-button attr-type="submit" type="primary">Search</n-button>
      </form>

      <div v-if="users.data.length" class="space-y-3">
        <article v-for="user in users.data" :key="user.id" class="rounded-lg border bg-white p-4 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 gap-3">
              <n-avatar :src="user.avatar" round>{{ initials(user.name) }}</n-avatar>
              <div>
                <h2 class="font-semibold text-gray-900">{{ user.name }}</h2>
                <p v-if="user.email" class="text-sm text-gray-600">{{ user.email }}</p>
                <p class="mt-1 text-sm text-gray-600">Last active: {{ formatDate(user.last_active_at) }}</p>
                <span :class="activityClass(user.activity_state)" class="mt-1 inline-block rounded-full px-2 py-1 text-xs font-medium">{{ activityLabel(user.activity_state) }} activity</span>
              </div>
            </div>
            <div class="text-sm text-gray-700">
              <p>{{ user.definitions_count }} definitions</p>
              <p>{{ user.reports_count }} reports</p>
              <n-button size="small" class="mt-2" @click="toggleDetails(user.id)">{{ expanded.has(user.id) ? 'Hide details' : 'View details' }}</n-button>
              <p v-if="user.suspended_at" class="mt-2 font-medium text-red-700">Suspended {{ formatDate(user.suspended_at) }}</p>
            </div>
          </div>
          <section v-if="expanded.has(user.id)" class="mt-3 border-t pt-3 text-sm text-gray-700" :aria-label="`Report details for ${user.name}`">
            <p>Word reports: {{ user.word_reports_count }}</p>
            <p>Definition reports: {{ user.definition_reports_count }}</p>
          </section>
          <div class="mt-4 border-t pt-3">
            <template v-if="user.suspended_at">
              <n-button type="primary" @click="openUnsuspend(user)">Restore account</n-button>
            </template>
            <template v-else-if="user.id === $page.props.auth.user.id">
              <span class="text-sm text-gray-500">You cannot suspend your own account.</span>
            </template>
            <template v-else>
              <n-button type="error" @click="openSuspend(user)">Suspend account</n-button>
            </template>
          </div>
        </article>
      </div>
      <p v-else class="rounded-lg border border-dashed p-8 text-center text-gray-600">No accounts match your search.</p>
      <div v-if="users.last_page > 1" class="mt-6 flex justify-center"><n-pagination v-model:page="page" :page-count="users.last_page" @update:page="goToPage" /></div>
    </main>

    <n-modal v-model:show="showModal" preset="card" :title="modalTitle" class="max-w-lg" :mask-closable="!submitting">
      <form @submit.prevent="submitAction">
        <p class="mb-4 text-gray-700">{{ modalMessage }}</p>
        <label v-if="modalAction === 'suspend'" class="block text-sm font-medium text-gray-700">Suspension reason
          <n-input
            v-model:value="reason"
            type="textarea"
            :rows="4"
            :maxlength="1000"
            required
            aria-label="Suspension reason"
            class="mt-1"
          />
        </label>
        <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <div class="mt-5 flex justify-end gap-3"><n-button @click="showModal = false">Cancel</n-button><n-button attr-type="submit" :type="modalAction === 'suspend' ? 'error' : 'primary'" :loading="submitting">{{ modalAction === 'suspend' ? 'Suspend account' : 'Restore account' }}</n-button></div>
      </form>
    </n-modal>
  </Layout>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Layout from '@/Components/Layout.vue'

const props = defineProps({ users: { type: Object, required: true }, filters: { type: Object, required: true } })
const search = ref(props.filters.search || '')
const sort = ref(props.filters.sort || 'last_active')
const direction = ref(props.filters.direction || 'desc')
const page = ref(props.users.current_page)
const expanded = ref(new Set())
const showModal = ref(false)
const modalAction = ref('suspend')
const selectedUser = ref(null)
const reason = ref('')
const error = ref('')
const submitting = ref(false)
const sortOptions = [{ label: 'Last active', value: 'last_active' }, { label: 'Name', value: 'name' }, { label: 'Email', value: 'email' }, { label: 'Definitions', value: 'definitions' }, { label: 'Reports', value: 'reports' }]
const directionOptions = [{ label: 'Descending', value: 'desc' }, { label: 'Ascending', value: 'asc' }]
const modalTitle = computed(() => modalAction.value === 'suspend' ? 'Suspend account' : 'Restore account')
const modalMessage = computed(() => modalAction.value === 'suspend' ? `Suspend ${selectedUser.value?.name}'s account? They will be signed out and unable to sign in.` : `Restore ${selectedUser.value?.name}'s account? They will be able to sign in again.`)
const query = (extra = {}) => ({ search: search.value || undefined, sort: sort.value, direction: direction.value, ...extra })
const applyFilters = () => router.get(route('admin.users.index'), query(), { preserveState: true, replace: true })
const goToPage = (newPage) => router.get(route('admin.users.index'), query({ page: newPage }), { preserveState: true })
const toggleDetails = (id) => { const next = new Set(expanded.value); next.has(id) ? next.delete(id) : next.add(id); expanded.value = next }
const openSuspend = (user) => { selectedUser.value = user; modalAction.value = 'suspend'; reason.value = ''; error.value = ''; showModal.value = true }
const openUnsuspend = (user) => { selectedUser.value = user; modalAction.value = 'unsuspend'; error.value = ''; showModal.value = true }
const submitAction = () => {
  if (modalAction.value === 'suspend' && !reason.value.trim()) { error.value = 'A suspension reason is required.'; return }
  submitting.value = true; error.value = ''
  const action = modalAction.value === 'suspend' ? 'suspend' : 'unsuspend'
  router.patch(route(`admin.users.${action}`, selectedUser.value.id), modalAction.value === 'suspend' ? { reason: reason.value.trim() } : {}, { preserveScroll: true, onError: (errors) => { error.value = errors.reason || errors.user || 'Unable to update this account.' }, onSuccess: () => { showModal.value = false }, onFinish: () => { submitting.value = false } })
}
const initials = (name) => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase()
const formatDate = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Unknown'
const activityLabel = (state) => ({ green: 'Active', orange: 'Recently active', red: 'Inactive or unknown' })[state]
const activityClass = (state) => ({ green: 'bg-green-100 text-green-800', orange: 'bg-orange-100 text-orange-800', red: 'bg-red-100 text-red-800' })[state]
</script>
