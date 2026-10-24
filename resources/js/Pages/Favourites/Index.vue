<template>
  <Layout>
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-8">{{ isPopular ? 'Most Favourited Words' : 'Your Favourite Words' }}</h1>

      <div v-if="words.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <a
          v-for="word in words.data"
          :key="word.id"
          :href="route('words.show', word.slug)"
          class="group block min-w-0 rounded-lg no-underline text-inherit focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        >
          <n-card class="h-full min-w-0 max-w-full overflow-hidden transition-shadow hover:shadow-lg">
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
                        @click.prevent.stop="playAudio(word)"
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
                        :aria-label="favouriteIds.includes(word.id) ? 'Remove from favourites' : 'Add to favourites'"
                        @click.prevent.stop="toggleFavourite(word)"
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
              <div v-if="isPopular" class="text-sm font-medium text-amber-600">
                {{ word.favourites_count }} favourite{{ word.favourites_count !== 1 ? 's' : '' }}
              </div>

              <div v-if="word.definitions.length > 0" class="text-gray-700">
                "{{ word.definitions[0].text }}"
                <div data-testid="favourites-definition-metadata" class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                  <span class="flex min-w-0 max-w-full items-center gap-1">
                    <n-avatar
                      v-if="word.definitions[0].user?.avatar"
                      :src="word.definitions[0].user.avatar"
                      :size="20"
                      round
                      class="shrink-0"
                    />
                    <n-avatar
                      v-else-if="word.definitions[0].user"
                      :size="20"
                      round
                      class="shrink-0"
                    >
                      {{ word.definitions[0].user.name.charAt(0).toUpperCase() }}
                    </n-avatar>
                    <span class="min-w-0 break-words">{{ word.definitions[0].user ? word.definitions[0].user.name : 'Deleted user' }}</span>
                  </span>
                  <span class="shrink-0 whitespace-nowrap">{{ word.definitions[0].votes_count }} votes</span>
                  <span v-if="word.definitions[0].part_of_speech" class="shrink-0 whitespace-nowrap">{{ word.definitions[0].part_of_speech }}</span>
                  <span v-if="word.definitions[0].origin === 'ai'" class="shrink-0 whitespace-nowrap">AI-generated</span>
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
        </a>
      </div>

      <div v-else class="text-center py-16 text-gray-400">
        <p class="text-lg">{{ isPopular ? 'No words have been favourited yet.' : "You haven't favourited any words yet." }}</p>
        <Link v-if="!isPopular" :href="route('home')" class="mt-4 inline-block">
          <n-button>Browse Words</n-button>
        </Link>
      </div>

      <!-- Pagination -->
      <div v-if="words.last_page > 1" class="flex justify-center mt-8">
        <n-pagination
          :page="words.current_page"
          :page-count="words.last_page"
          @update:page="onPageChange"
        />
      </div>

      <audio ref="audioRef" @ended="playingWordId = null" @error="playingWordId = null" />
    </div>
  </Layout>
</template>

<script setup>
import { router, Link, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { mdiPlay, mdiStar } from '@mdi/js'
import Layout from '@/Components/Layout.vue'

const props = defineProps({
  words: { type: Object, required: true },
  isPopular: { type: Boolean, default: false },
})

const page = usePage()

const audioRef = ref(null)
const playingWordId = ref(null)

const favouriteIds = computed(() => page.props.auth?.favourite_ids ?? [])

const favouriteIconStyle = (word) => {
  const isFav = favouriteIds.value.includes(word.id)
  return {
    fill: isFav ? '#f59e0b' : 'none',
    stroke: isFav ? '#f59e0b' : 'currentColor',
    strokeWidth: '2',
  }
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

const toggleFavourite = (word) => {
  router.post(route('words.favourite', word.slug), {}, {
    preserveScroll: true,
    preserveState: true,
  })
}

const onPageChange = (pageNum) => {
  router.get(route(props.isPopular ? 'words.popular' : 'words.favourites'), { page: pageNum }, {
    preserveScroll: true,
    preserveState: true,
  })
}
</script>
