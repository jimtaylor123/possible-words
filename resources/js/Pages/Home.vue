<template>
  <Layout>
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <!-- Search and Controls -->
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">Discover Available Words</h1>

        <div class="flex items-center gap-3">
          <n-auto-complete
            v-model:value="filters.search"
            :options="suggestionOptions"
            :loading="suggestionsLoading"
            :get-show="getSuggestionShow"
            placeholder="Search words..."
            clearable
            show-empty
            class="flex-1"
            @select="onSuggestionSelect"
          >
            <template #prefix>
              <svg viewBox="0 0 24 24" width="20" height="20" style="fill: currentColor;">
                <path :d="mdiMagnify" />
              </svg>
            </template>
            <template #empty>
              <div class="px-3 py-2 text-sm text-gray-500">
                No matching words
              </div>
            </template>
          </n-auto-complete>
          <n-button
            quaternary
            circle
            :aria-label="showAdvanced ? 'Hide filters' : 'Show filters'"
            @click="toggleAdvanced"
          >
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
          <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
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

      <!-- Words Grid -->
      <div v-if="allWords.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div
          v-for="word in allWords"
          :key="word.id"
          class="group block rounded-lg no-underline text-inherit cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
          role="link"
          :tabindex="0"
          @click="handleCardClick(word, $event)"
          @keydown.enter="router.visit(route('words.show', word.slug))"
        >
          <n-card class="hover:shadow-lg transition-shadow h-full">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="text-xl font-bold text-blue-600 group-hover:text-blue-800">
                  {{ word.text }}
                </span>
                <div class="flex items-center gap-1">
                  <n-tooltip v-if="word.audio_url" trigger="hover">
                    <template #trigger>
                      <n-button
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
                    </template>
                    Click to hear the word
                  </n-tooltip>
                  <n-tooltip v-if="$page.props.auth.user" trigger="hover">
                    <template #trigger>
                      <n-button
                        size="small"
                        quaternary
                        circle
                        @click.stop="toggleFavourite(word)"
                      >
                        <template #icon>
                          <svg viewBox="0 0 24 24" :width="20" :height="20" :style="favouriteIconStyle(word)">
                            <path :d="mdiStar" />
                          </svg>
                        </template>
                      </n-button>
                    </template>
                    {{ favouriteIds.includes(word.id) ? 'Remove from favourites' : 'Add to favourites' }}
                  </n-tooltip>
                </div>
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
import { router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { mdiFilter, mdiFilterOff, mdiMagnify, mdiPlay, mdiStar } from '@mdi/js'
import Layout from '@/Components/Layout.vue'

const page = usePage()
const favouriteIds = computed(() => page.props.auth?.favourite_ids ?? [])

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

const favouriteIconStyle = (word) => {
  const isFav = favouriteIds.value.includes(word.id)
  return {
    fill: isFav ? '#f59e0b' : 'none',
    stroke: isFav ? '#f59e0b' : 'currentColor',
    strokeWidth: '2',
  }
}

const toggleFavourite = (word) => {
  router.post(route('words.favourite', word.slug), {}, {
    preserveScroll: true,
    preserveState: true,
  })
}

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
const suggestionsMinLength = 2
let searchTimer = null
let suggestionsRequestId = 0
let suggestionsAbortController = null

const suggestionOptions = ref([])
const suggestionsLoading = ref(false)

const debouncedSearch = () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    search()
    fetchSuggestions()
  }, debounceMs)
}

const fetchSuggestions = async () => {
  const term = (filters.value.search || '').trim()

  if (term.length < suggestionsMinLength) {
    suggestionOptions.value = []
    suggestionsLoading.value = false
    return
  }

  suggestionsLoading.value = true

  const requestId = ++suggestionsRequestId
  if (suggestionsAbortController) {
    suggestionsAbortController.abort()
  }
  const controller = new AbortController()
  suggestionsAbortController = controller

  try {
    const response = await fetch(route('words.suggestions', { q: term }), {
      signal: controller.signal,
    })

    if (!response.ok) {
      throw new Error(`Suggestions request failed: ${response.status}`)
    }

    const words = await response.json()

    if (requestId !== suggestionsRequestId) {
      return
    }

    suggestionOptions.value = words.map((word) => ({
      label: word.text,
      value: word.slug,
      syllables: word.syllables,
    }))
  } catch (error) {
    if (error.name === 'AbortError' || requestId !== suggestionsRequestId) {
      return
    }
    suggestionOptions.value = []
  } finally {
    if (requestId === suggestionsRequestId) {
      suggestionsLoading.value = false
    }
  }
}

const getSuggestionShow = (value) => {
  const term = (value || '').trim()
  return term.length >= suggestionsMinLength
}

const onSuggestionSelect = (value) => {
  if (value) {
    router.visit(route('words.show', value))
  }
}

watch(() => filters.value.search, debouncedSearch)

watch(() => filters.value.starts_with, debouncedSearch)

onBeforeUnmount(() => {
  clearTimeout(searchTimer)
  suggestionsRequestId++
  if (suggestionsAbortController) {
    suggestionsAbortController.abort()
  }
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
  filters.value = { search: null, syllables: null, length: null, starts_with: null }
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
