<!-- eslint-disable vue/component-name-in-template-casing, vue/max-attributes-per-line -->
<template>
  <section aria-labelledby="user-directory-heading">
    <h2 id="user-directory-heading" class="text-2xl font-bold text-gray-900">User management</h2>
    <p class="mb-6 text-gray-600">Inspect account activity and manage account access.</p>

    <div class="mb-6">
      <n-input v-model:value="search" aria-label="Search users" placeholder="Search name or email" @update:value="queueSearch" />
    </div>

    <n-data-table
      remote
      :columns="columns"
      :data="users.data"
      :pagination="pagination"
      :row-key="(user) => user.id"
      :single-line="false"
      table-layout="fixed"
      aria-label="User directory"
      @update:sorter="updateSorter"
      @update:page="goToPage"
    />

    <n-modal v-model:show="showModal" preset="card" :title="modalTitle" class="max-w-lg" :mask-closable="!submitting">
      <form @submit.prevent="submitAction">
        <p class="mb-4 text-gray-700">{{ modalMessage }}</p>
        <label v-if="modalAction === 'suspend'" class="block text-sm font-medium text-gray-700">Suspension reason
          <n-input v-model:value="reason" type="textarea" :rows="4" :maxlength="1000" required aria-label="Suspension reason" class="mt-1" />
        </label>
        <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <div class="mt-5 flex justify-end gap-3"><n-button @click="showModal = false">Cancel</n-button><n-button attr-type="submit" :type="modalAction === 'suspend' ? 'error' : 'primary'" :loading="submitting">{{ modalAction === 'suspend' ? 'Suspend account' : 'Restore account' }}</n-button></div>
      </form>
    </n-modal>
  </section>
</template>

<script setup>
import { mdiAccountCancel } from '@mdi/js'
import { router, usePage } from '@inertiajs/vue3'
import { computed, h, onBeforeUnmount, ref, resolveComponent } from 'vue'

const props = defineProps({ users: { type: Object, required: true }, filters: { type: Object, required: true } })
const inertiaPage = usePage()
const NButton = resolveComponent('NButton')
const search = ref(props.filters.search || '')
const sort = ref(props.filters.sort || 'last_active')
const direction = ref(props.filters.direction || 'desc')
const showModal = ref(false)
const modalAction = ref('suspend')
const selectedUser = ref(null)
const reason = ref('')
const error = ref('')
const submitting = ref(false)
const failedAvatarIds = ref(new Set())
let searchTimer
const modalTitle = computed(() => modalAction.value === 'suspend' ? 'Suspend account' : 'Restore account')
const modalMessage = computed(() => modalAction.value === 'suspend' ? `Suspend ${selectedUser.value?.name}'s account? They will be signed out and unable to sign in.` : `Restore ${selectedUser.value?.name}'s account? They will be able to sign in again.`)
const query = (extra = {}) => ({ tab: 'users', search: search.value || undefined, sort: sort.value, direction: direction.value, ...extra })
const applyFilters = () => router.get(route('admin.dashboard'), query(), { preserveState: true, replace: true })
const queueSearch = () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 300)
}
const goToPage = (newPage) => router.get(route('admin.dashboard'), query({ page: newPage }), { preserveState: true })
const updateSorter = (sorter) => {
  if (!sorter?.columnKey || !sorter.order) return
  direction.value = sort.value === sorter.columnKey
    ? (direction.value === 'asc' ? 'desc' : 'asc')
    : (sorter.order === 'descend' ? 'desc' : 'asc')
  sort.value = sorter.columnKey
  applyFilters()
}
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
const activityLabel = (state) => ({ green: 'Online', orange: 'Recent', red: 'Inactive' })[state]
const activityClass = (state) => ({ green: 'bg-green-100 text-green-800', orange: 'bg-orange-100 text-orange-800', red: 'bg-red-100 text-red-800' })[state]
const sortOrder = (key) => sort.value === key ? (direction.value === 'asc' ? 'ascend' : 'descend') : false
const avatar = (user) => user.avatar && !failedAvatarIds.value.has(user.id)
  ? h('img', { src: user.avatar, alt: `${user.name}'s avatar`, referrerpolicy: 'no-referrer', class: 'h-[34px] w-[34px] shrink-0 rounded-full object-cover', onError: () => { failedAvatarIds.value = new Set([...failedAvatarIds.value, user.id]) } })
  : h('span', { class: 'flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-gray-200 text-sm text-gray-700' }, initials(user.name))
const columns = computed(() => [
  { title: 'Account', key: 'name', sorter: true, sortOrder: sortOrder('name'), minWidth: 240, render: (user) => h('div', { class: 'flex min-w-0 items-center gap-3' }, [avatar(user), h('div', { class: 'min-w-0' }, [h('p', { class: 'truncate font-semibold text-gray-900' }, user.name), user.email ? h('a', { href: `mailto:${user.email}`, class: 'block max-w-full truncate text-gray-600 hover:text-blue-700 hover:underline', title: user.email }, user.email) : null])]) },
  { title: 'Last active', key: 'last_active', sorter: true, sortOrder: sortOrder('last_active'), minWidth: 150, render: (user) => h('div', [h('span', { class: `${activityClass(user.activity_state)} inline-block rounded-full px-2 py-1 text-xs font-medium` }, activityLabel(user.activity_state)), h('p', { class: 'mt-1 text-xs text-gray-600' }, formatDate(user.last_active_at))]) },
  { title: 'Definitions', key: 'definitions', sorter: true, sortOrder: sortOrder('definitions'), minWidth: 100, render: (user) => h('span', { class: 'font-medium text-gray-800' }, user.definitions_count) },
  { title: 'Reports', key: 'reports', sorter: true, sortOrder: sortOrder('reports'), minWidth: 130, render: (user) => h('div', [h('span', { class: 'font-medium text-gray-800' }, user.reports_count), h('p', { class: 'text-xs text-gray-600' }, `${user.word_reports_count} word · ${user.definition_reports_count} definition`)]) },
  { title: 'Account state', key: 'state', minWidth: 130, render: (user) => h('p', { class: user.suspended_at ? 'font-medium text-red-700' : 'font-medium text-green-700' }, user.suspended_at ? `Suspended ${formatDate(user.suspended_at)}` : 'Active') },
  { title: 'Actions', key: 'actions', width: 90, align: 'right', render: (user) => user.suspended_at ? h(NButton, { size: 'small', type: 'primary', onClick: () => openUnsuspend(user) }, { default: () => 'Restore' }) : user.id === inertiaPage.props.auth.user.id ? h('span', { class: 'text-xs text-gray-500' }, 'Current user') : h(NButton, { size: 'small', type: 'error', secondary: true, circle: true, 'aria-label': `Suspend ${user.name}'s account`, title: `Suspend ${user.name}'s account`, onClick: () => openSuspend(user) }, { default: () => h('svg', { 'aria-hidden': 'true', class: 'h-4 w-4', viewBox: '0 0 24 24' }, [h('path', { d: mdiAccountCancel, fill: 'currentColor' })]) }) },
])
const pagination = computed(() => ({ page: props.users.current_page, pageCount: props.users.last_page, itemCount: props.users.total, pageSize: props.users.per_page, showSizePicker: false }))
onBeforeUnmount(() => clearTimeout(searchTimer))
</script>
