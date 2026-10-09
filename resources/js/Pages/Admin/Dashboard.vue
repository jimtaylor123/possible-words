<template>
  <Layout>
    <div class="max-w-5xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2">Report queue</h1>
      <p class="text-gray-600 mb-6">Review submitted reports without changing reported content.</p>

      <p v-if="$page.props.flash?.success" role="status" class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-green-800">
        {{ $page.props.flash.success }}
      </p>
      <p v-if="$page.props.flash?.error" role="alert" class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-red-800">
        {{ $page.props.flash.error }}
      </p>

      <div class="grid gap-4 sm:grid-cols-2 mb-6">
        <label class="block text-sm font-medium text-gray-700">
          Report type
          <n-select v-model:value="selectedReason" aria-label="Report type" :options="reasonOptions" class="mt-1" @update:value="applyFilters" />
        </label>
        <label class="block text-sm font-medium text-gray-700">
          Lifecycle status
          <n-select v-model:value="selectedStatus" aria-label="Lifecycle status" :options="statusOptions" class="mt-1" @update:value="applyFilters" />
        </label>
      </div>

      <div v-if="reports.data.length" class="space-y-4">
        <article v-for="report in reports.data" :key="report.id" class="rounded-lg border bg-white p-5 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="font-semibold text-gray-900">{{ reasonLabel(report.reason) }}</p>
              <span :class="statusClass(report.status)" class="inline-block rounded-full px-2 py-1 text-xs font-medium mt-1">{{ report.status }}</span>
            </div>
            <n-button size="small" type="primary" @click="openAction(report)">{{ actionLabel(report) }}</n-button>
          </div>

          <div class="mt-4 space-y-2 text-sm text-gray-700">
            <p>
              <span class="font-medium">Reported {{ report.target.type }}:</span>
              <a v-if="report.target.url" :href="report.target.url" class="text-blue-600 hover:underline">{{ report.target.text }}</a>
              <span v-else>{{ report.target.text || 'Deleted content' }}</span>
              <span v-if="report.target.type === 'definition' && report.target.word_text"> (for {{ report.target.word_text }})</span>
            </p>
            <p><span class="font-medium">Reporter:</span> {{ report.reporter?.name || 'Deleted user' }}</p>
            <p><span class="font-medium">Submitted:</span> {{ formatDate(report.submitted_at) }}</p>
            <p><span class="font-medium">Context:</span> <span v-if="report.explanation">{{ report.explanation }}</span><em v-else>No context provided.</em></p>
            <p v-if="report.latest_review"><span class="font-medium">Latest review:</span> {{ report.latest_review.action }} by {{ report.latest_review.admin?.name || 'Deleted user' }} on {{ formatDate(report.latest_review.processed_at) }}<span v-if="report.latest_review.note"> — {{ report.latest_review.note }}</span></p>
          </div>

          <section v-if="report.actions.length" class="mt-4 border-t pt-3" :aria-label="`Review history for report ${report.id}`">
            <h2 class="text-sm font-semibold text-gray-800">Review history</h2>
            <ol class="mt-2 space-y-1 text-sm text-gray-600">
              <li v-for="action in report.actions" :key="action.id"><span class="font-medium">{{ action.action }}</span> by {{ action.admin?.name || 'Deleted user' }} on {{ formatDate(action.created_at) }}<span v-if="action.note"> — {{ action.note }}</span></li>
            </ol>
          </section>
        </article>
      </div>
      <p v-else class="rounded-lg border border-dashed p-8 text-center text-gray-600">{{ isFiltered ? 'No reports match these filters.' : 'There are no open reports.' }}</p>

      <div v-if="reports.last_page > 1" class="mt-6 flex justify-center">
        <n-pagination v-model:page="currentPage" :page-count="reports.last_page" @update:page="goToPage" />
      </div>
    </div>

    <n-modal v-model:show="showAction" preset="card" title="Update report" class="max-w-lg" :mask-closable="!submitting">
      <form @submit.prevent="submitAction">
        <p class="mb-4 text-gray-700">Mark this report as <strong>{{ selectedAction }}</strong>. This does not change the reported content.</p>
        <label v-if="selectedReport?.status === 'open'" class="block mb-4 text-sm font-medium text-gray-700">Action
          <n-select v-model:value="selectedAction" :options="openActionOptions" class="mt-1" aria-label="Review action" />
        </label>
        <label class="block text-sm font-medium text-gray-700">Internal note (optional)
          <n-input
            v-model:value="note"
            type="textarea"
            :maxlength="1000"
            :rows="4"
            class="mt-1"
            aria-label="Internal note"
          />
        </label>
        <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <div class="mt-5 flex justify-end gap-3"><n-button @click="showAction = false">Cancel</n-button><n-button attr-type="submit" type="primary" :loading="submitting">{{ selectedAction }}</n-button></div>
      </form>
    </n-modal>
  </Layout>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Layout from '@/Components/Layout.vue'

const props = defineProps({
  reports: { type: Object, required: true },
  filters: { type: Object, required: true },
  reasonOptions: { type: Array, required: true },
  statusOptions: { type: Array, required: true },
})

const selectedReason = ref(props.filters.reason)
const selectedStatus = ref(props.filters.status)
const currentPage = ref(props.reports.current_page)
const showAction = ref(false)
const selectedReport = ref(null)
const selectedAction = ref('')
const note = ref('')
const error = ref('')
const submitting = ref(false)
const isFiltered = computed(() => selectedReason.value || selectedStatus.value !== 'open')
const openActionOptions = [
  { label: 'Review', value: 'reviewed' },
  { label: 'Dismiss', value: 'dismissed' },
]

const applyFilters = () => {
  currentPage.value = 1
  router.get(route('admin.dashboard'), { reason: selectedReason.value || undefined, status: selectedStatus.value }, { preserveState: true, replace: true })
}

const goToPage = (page) => {
  router.get(route('admin.dashboard'), { reason: selectedReason.value || undefined, status: selectedStatus.value, page }, { preserveState: true })
}

const actionLabel = (report) => report.status === 'open' ? 'Review or dismiss' : 'Reopen'
const openAction = (report) => {
  selectedReport.value = report
  selectedAction.value = report.status === 'open' ? 'reviewed' : 'reopened'
  note.value = ''
  error.value = ''
  showAction.value = true
}

const submitAction = () => {
  submitting.value = true
  error.value = ''
  router.patch(route('admin.reports.update', selectedReport.value.id), { action: selectedAction.value, note: note.value || null }, {
    preserveScroll: true,
    onError: (errors) => { error.value = errors.action || errors.note },
    onSuccess: () => { showAction.value = false },
    onFinish: () => { submitting.value = false },
  })
}

const reasonLabel = (reason) => ({ word_unfresh: 'Unfresh word', word_offensive: 'Offensive word', definition_offensive: 'Offensive definition' })[reason] || reason
const statusClass = (status) => ({ open: 'bg-amber-100 text-amber-800', reviewed: 'bg-blue-100 text-blue-800', dismissed: 'bg-gray-100 text-gray-700' })[status]
const formatDate = (date) => date ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(date)) : 'Unknown time'
</script>
