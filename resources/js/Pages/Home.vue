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
      <div v-if="allWords.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div
          v-for="word in allWords"
          :key="word.id"
          @click="handleCardClick(word, $event)"
          class="group block rounded-lg no-underline text-inherit cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
          role="link"
          :tabindex="0"
          @keydown.enter="router.visit(route('words.show', word.slug))"
        >
          <n-card class="hover:shadow-lg transition-shadow h-full">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="text-xl font-bold text-blue-600 group-hover:text-blue-800">
                  {{ word.text }}
                </span>
                <n-button
                  v-if="word.audio_url"
                  size="small"
                  quaternary
                  circle
                  @click="playAudio(word)"
                >
                  <template #icon>
                    <svg viewBox="0 0 24 24" width="20" height="20" style="fill: currentColor;">
                      <path :d="mdiPlay" />
                    </svg>
                  </template>
                </n-button>
              </div>
            </template>

            <div class="space-y-3">
              <div class="flex items-center space-x-4 text-sm text-gray-500">
                <span>{{ word.syllables }} syllable{{ word.syllables !== 1 ? 's' : '' }}</span>
                <span>{{ word.text.length }} letters</span>
              </div>

              <div v-if="word.definitions.length > 0" class="text-gray-700">
                "{{ word.definitions[0].text }}"
                <div class="flex items-center gap-2 text-xs text-gray-500 mt-2">
                  <n-avatar
                    v-if="word.definitions[0].user.avatar"
                    :src="word.definitions[0].user.avatar"
                    :size="20"
                    round
                  />
                  <n-avatar
                    v-else
                    :size="20"
                    round
                  >
                    {{ word.definitions[0].user.name.charAt(0).toUpperCase() }}
                  </n-avatar>
                  <span>{{ word.definitions[0].user.name }}</span>
                  <span>&bull;</span>
                  <span>{{ word.definitions[0].votes_count }} votes</span>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                  {{ word.definitions_count }} definition{{ word.definitions_count !== 1 ? 's' : '' }} total
                </div>
              </div>
              <div v-else class="text-gray-400 italic">
                No definitions yet
              </div>
            </div>
          </n-card>
        </div>
      </div>

      <div v-else class="text-center py-16 text-gray-400">
        <p class="text-lg">No words found matching your criteria.</p>
        <n-button class="mt-4" @click="clearFilters">Clear Filters</n-button>
      </div>

      <!-- Infinite Scroll Sentinel -->
      <div ref="sentinelRef" class="flex items-center justify-center py-8">
        <n-spin v-if="loadingMore" size="small" />
        <span v-else-if="!hasMore && allWords.length > 0" class="text-gray-400 text-sm">
          No more words. Did you just read the entire dictionary?
        </span>
      </div>

      <audio ref="audioRef" @ended="playingWordId = null" @error="playingWordId = null" />
    </div>
  </Layout>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { mdiFilter, mdiFilterOff, mdiPlay } from '@mdi/js'
import Layout from '@/Components/Layout.vue'

const props = defineProps({
  words: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
})

const showAdvanced = ref(false)
const filters = ref({ ...props.filters })

const allWords = ref([])
const loadingMore = ref(false)
const hasMore = ref(true)
const sentinelRef = ref(null)
let observer = null

const audioRef = ref(null)
const playingWordId = ref(null)

const playAudio = (word) => {
  if (!audioRef.value || !word.audio_url) return
  if (playingWordId.value === word.id) {
    audioRef.value.currentTime = 0
    audioRef.value.play()
    return
  }
  playingWordId.value = word.id
  audioRef.value.src = word.audio_url
  audioRef.value.play()
}

const handleCardClick = (word, event) => {
  if (event.target.closest('.n-button')) return
  router.visit(route('words.show', word.slug))
}

watch(() => props.words, (newWords) => {
  if (!newWords?.data) return
  if (newWords.current_page === 1 || allWords.value.length === 0) {
    allWords.value = [...newWords.data]
  } else {
    const existingIds = new Set(allWords.value.map(w => w.id))
    const newItems = newWords.data.filter(w => !existingIds.has(w.id))
    allWords.value.push(...newItems)
  }
  hasMore.value = newWords.current_page < newWords.last_page
}, { immediate: true })

const loadMore = () => {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true

  const nextPage = (props.words?.current_page || 1) + 1

  router.visit(route('home'), {
    data: { ...buildParams(), page: nextPage },
    only: ['words'],
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      loadingMore.value = false
    },
  })
}

onMounted(() => {
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) {
        loadMore()
      }
    },
    { rootMargin: '400px' }
  )

  if (sentinelRef.value) {
    observer.observe(sentinelRef.value)
  }
})

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
  if (observer) observer.disconnect()
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
