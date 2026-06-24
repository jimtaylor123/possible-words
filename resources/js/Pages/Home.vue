<template>
  <Layout>
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <!-- Search and Controls -->
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">Discover Available Words</h1>

        <div class="bg-white p-6 rounded-lg shadow-sm border">
          <div class="flex items-center gap-4">
            <n-input
              v-model:value="filters.search"
              placeholder="Search words..."
              clearable
              class="flex-1"
            />
            <n-button @click="toggleAdvanced">
              <template #icon>
                <svg v-if="!showAdvanced" viewBox="0 0 24 24" width="22" height="22" style="fill: currentColor;">
                  <path :d="mdiFilter" />
                </svg>
                <svg v-else viewBox="0 0 24 24" width="22" height="22" style="fill: currentColor;">
                  <path :d="mdiFilterOff" />
                </svg>
              </template>
            </n-button>
          </div>

          <transition name="fade">
            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4 pt-4 border-t">
              <n-select
                v-model:value="filters.syllables"
                placeholder="Syllables"
                clearable
                :options="syllableOptions"
                @update:value="search"
              />
              <n-select
                v-model:value="filters.length"
                placeholder="Length"
                clearable
                :options="lengthOptions"
                @update:value="search"
              />
              <n-input
                v-model:value="filters.starts_with"
                placeholder="Starts with..."
                clearable
              />
              <n-select
                v-model:value="sortValue"
                :options="sortOptions"
                placeholder="Sort by..."
                @update:value="onSortChange"
              />
            </div>
          </transition>
        </div>
      </div>

      <!-- Words Grid -->
      <div v-if="words.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <a
          v-for="word in words.data"
          :key="word.id"
          :href="route('words.show', word.slug)"
          class="group block rounded-lg no-underline text-inherit focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        >
          <n-card class="hover:shadow-lg transition-shadow h-full">
            <template #header>
              <span class="text-xl font-bold text-blue-600 group-hover:text-blue-800">
                {{ word.text }}
              </span>
            </template>

            <div class="space-y-3">
              <div class="flex items-center space-x-4 text-sm text-gray-500">
                <span>{{ word.syllables }} syllable{{ word.syllables !== 1 ? 's' : '' }}</span>
                <span>{{ word.text.length }} letters</span>
              </div>

              <div v-if="word.definitions.length > 0" class="space-y-2">
                <div v-for="definition in word.definitions" :key="definition.id" class="text-gray-700">
                  "{{ definition.text }}"
                  <div class="text-xs text-gray-500 mt-1">
                    by {{ definition.user.name }} &bull; {{ definition.votes_count }} votes
                  </div>
                </div>
              </div>
              <div v-else class="text-gray-400 italic">
                No definitions yet
              </div>
            </div>
          </n-card>
        </a>
      </div>

      <div v-else class="text-center py-16 text-gray-400">
        <p class="text-lg">No words found matching your criteria.</p>
        <n-button class="mt-4" @click="clearFilters">Clear Filters</n-button>
      </div>

      <!-- Pagination -->
      <div v-if="words.last_page > 1" class="mt-8 flex justify-center">
        <n-pagination
          v-model:page="currentPage"
          :page-count="words.last_page"
          @update:page="handlePageChange"
        />
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { ref, watch, onBeforeUnmount } from 'vue'
import { mdiFilter, mdiFilterOff } from '@mdi/js'
import Layout from '@/Components/Layout.vue'

const props = defineProps({
  words: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const showAdvanced = ref(false)
const filters = ref({ ...props.filters })
const currentPage = ref(props.words.current_page)

const debounceMs = Number(import.meta.env.VITE_SEARCH_DEBOUNCE_MS) || 200
let searchTimer = null

const debouncedSearch = () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    search()
  }, debounceMs)
}

watch(() => filters.value.search, debouncedSearch)

watch(() => filters.value.starts_with, debouncedSearch)

onBeforeUnmount(() => {
  clearTimeout(searchTimer)
})

const sortOptions = [
  { label: 'Newest First', value: 'created_at-desc' },
  { label: 'Oldest First', value: 'created_at-asc' },
  { label: 'Alphabetical (A-Z)', value: 'alphabetical-asc' },
  { label: 'Alphabetical (Z-A)', value: 'alphabetical-desc' },
  { label: 'Shortest First', value: 'letters-asc' },
  { label: 'Longest First', value: 'letters-desc' },
  { label: 'Most Popular', value: 'popularity-desc' },
  { label: 'Least Popular', value: 'popularity-asc' },
]

const sortValue = ref(`${props.filters.sort}-${props.filters.direction}`)

const syllableOptions = [
  { label: '1 syllable', value: 1 },
  { label: '2 syllables', value: 2 },
  { label: '3 syllables', value: 3 },
  { label: '4 syllables', value: 4 },
  { label: '5+ syllables', value: 5 },
]

const lengthOptions = [
  { label: '3 letters', value: 3 },
  { label: '4 letters', value: 4 },
  { label: '5 letters', value: 5 },
  { label: '6 letters', value: 6 },
  { label: '7 letters', value: 7 },
  { label: '8+ letters', value: 8 },
]

const buildParams = () => {
  const params = {}
  for (const key in filters.value) {
    if (filters.value[key] !== null && filters.value[key] !== undefined && filters.value[key] !== '') {
      params[key] = filters.value[key]
    }
  }
  const parts = sortValue.value.split('-')
  params.sort = parts[0]
  params.direction = parts[1]
  return params
}

const search = () => {
  router.get(route('home'), buildParams(), {
    preserveState: true,
    replace: true,
  })
}

const onSortChange = () => {
  search()
}

const clearFilters = () => {
  filters.value = {}
  router.get(route('home'), { sort: 'created_at', direction: 'desc' }, {
    preserveState: true,
    replace: true,
  })
}

const toggleAdvanced = () => {
  if (showAdvanced.value) {
    showAdvanced.value = false
    clearFilters()
  } else {
    showAdvanced.value = true
  }
}

const handlePageChange = (page) => {
  router.get(route('home'), { ...buildParams(), page }, {
    preserveState: true,
  })
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
