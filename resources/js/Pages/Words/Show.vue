<template>
  <Layout>
    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <!-- Word Header -->
      <div class="text-center mb-8">
        <div class="flex items-center justify-center gap-3 mb-4">
          <h1 class="text-4xl font-bold text-gray-900">{{ word.text }}</h1>
          <n-tooltip v-if="$page.props.auth.user" trigger="hover">
            <template #trigger>
              <n-button
                size="small"
                quaternary
                circle
                :aria-label="favouriteIds.includes(props.word.id) ? 'Remove from favourites' : 'Add to favourites'"
                @click="toggleFavourite"
              >
                <template #icon>
                  <svg viewBox="0 0 24 24" :width="24" :height="24" :style="favouriteIconStyle">
                    <path :d="mdiStar" />
                  </svg>
                </template>
              </n-button>
            </template>
            {{ favouriteIds.includes(props.word.id) ? 'Remove from favourites' : 'Add to favourites' }}
          </n-tooltip>
        </div>
        <div class="flex justify-center space-x-4 text-gray-500">
          <span>{{ word.syllables }} syllable{{ word.syllables !== 1 ? 's' : '' }}</span>
          <span>{{ word.text.length }} letters</span>
        </div>
        <div v-if="word.ipa" class="flex items-center justify-center gap-3 mt-3">
          <span class="text-lg text-neutral-500 font-mono">{{ word.ipa }}</span>
          <n-tooltip v-if="word.audio_url" trigger="hover">
            <template #trigger>
              <n-button
                size="small"
                quaternary
                circle
                @click="playAudio"
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
          <audio ref="audioPlayer" :src="word.audio_url" @ended="playing = false" @error="playing = false" />
        </div>
      </div>

      <!-- Share Section -->
      <div class="flex items-center justify-center gap-2 mb-8">
        <span class="text-sm text-gray-400 mr-1">Share</span>
        <n-tooltip trigger="hover">
          <template #trigger>
            <n-button size="small" quaternary circle @click="shareWhatsApp">
              <template #icon>
                <svg viewBox="0 0 24 24" width="20" height="20" style="fill: #25D366;">
                  <path :d="mdiWhatsapp" />
                </svg>
              </template>
            </n-button>
          </template>
          WhatsApp
        </n-tooltip>
        <n-tooltip trigger="hover">
          <template #trigger>
            <n-button size="small" quaternary circle @click="shareFacebook">
              <template #icon>
                <svg viewBox="0 0 24 24" width="20" height="20" style="fill: #1877F2;">
                  <path :d="mdiFacebook" />
                </svg>
              </template>
            </n-button>
          </template>
          Facebook
        </n-tooltip>
        <n-tooltip trigger="hover">
          <template #trigger>
            <n-button size="small" quaternary circle @click="shareTwitter">
              <template #icon>
                <svg viewBox="0 0 24 24" width="20" height="20" style="fill: currentColor;">
                  <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
                </svg>
              </template>
            </n-button>
          </template>
          X / Twitter
        </n-tooltip>
        <n-tooltip trigger="hover">
          <template #trigger>
            <n-button size="small" quaternary circle @click="shareLinkedIn">
              <template #icon>
                <svg viewBox="0 0 24 24" width="20" height="20" style="fill: #0A66C2;">
                  <path :d="mdiLinkedin" />
                </svg>
              </template>
            </n-button>
          </template>
          LinkedIn
        </n-tooltip>
      </div>

      <!-- Definitions Section -->
      <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Definitions</h2>
        
        <div v-if="definitions.length > 0" class="space-y-4">
          <div
            v-for="definition in definitions"
            :key="definition.id"
            class="border rounded-lg p-4"
          >
            <div class="flex justify-between items-start mb-2">
              <p class="text-gray-800">{{ definition.text }}</p>
              <div class="flex items-center gap-1 ml-2 shrink-0">
                <button
                  v-if="$page.props.auth.user"
                  class="flex items-center justify-center rounded p-1 hover:bg-gray-100 transition-colors"
                  :disabled="animatingId === definition.id"
                  @click="vote(definition.id)"
                >
                  <svg
                    viewBox="0 0 24 24"
                    width="20"
                    height="20"
                    :class="[
                      'transition-transform duration-200 block',
                      likedByUser(definition) ? 'fill-blue-500' : 'fill-none stroke-current',
                      { 'scale-125': animatingId === definition.id }
                    ]"
                  >
                    <path :d="mdiThumbUp" />
                  </svg>
                </button>
                <span class="text-sm text-gray-500 w-14 text-right tabular-nums">{{ definition.votes_count }} {{ definition.votes_count === 1 ? 'like' : 'likes' }}</span>
              </div>
            </div>
            <div class="text-sm text-gray-500 flex items-center gap-1.5">
              <n-avatar
                round
                size="small"
                :src="definition.user.avatar || undefined"
                :alt="definition.user.name"
                :img-props="{ referrerPolicy: 'no-referrer' }"
              >
                <template #fallback>
                  {{ definition.user.name.charAt(0).toUpperCase() }}
                </template>
              </n-avatar>
              {{ definition.user.name }}
            </div>
          </div>
        </div>
        
        <div v-else class="text-gray-400 italic text-center py-8">
          No definitions yet. Be the first to define this word!
        </div>
      </div>

      <!-- Add Definition Form -->
      <div v-if="$page.props.auth.user" class="bg-white rounded-lg shadow-sm border p-6">
        <h3 class="text-xl font-bold text-gray-900 mb-4">Add a Definition</h3>
        <form @submit.prevent="submitDefinition">
          <n-input
            v-model:value="definitionText"
            type="textarea"
            placeholder="What does this word mean?"
            :rows="3"
            class="mb-4"
          />
          <n-button type="primary" :loading="submitting" @click="submitDefinition">
            Submit Definition
          </n-button>
        </form>
      </div>

      <!-- Login Prompt -->
      <div v-else class="bg-blue-50 rounded-lg border border-blue-200 p-6 text-center">
        <p class="text-blue-800 mb-4">Want to add a definition or vote?</p>
        <div class="flex justify-center">
          <GoogleSignInButton @click="login" />
        </div>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { mdiPlay, mdiStar, mdiThumbUp, mdiWhatsapp, mdiFacebook, mdiLinkedin } from '@mdi/js'
import Layout from '@/Components/Layout.vue'
import GoogleSignInButton from '@/Components/GoogleSignInButton.vue'

const props = defineProps({
  word: { type: Object, required: true },
})

const page = usePage()
const playing = ref(false)
const audioPlayer = ref(null)

const favouriteIds = computed(() => page.props.auth?.favourite_ids ?? [])

const favouriteIconStyle = computed(() => {
  const isFav = favouriteIds.value.includes(props.word.id)
  return {
    fill: isFav ? '#f59e0b' : 'none',
    stroke: isFav ? '#f59e0b' : 'currentColor',
    strokeWidth: '2',
  }
})

const toggleFavourite = () => {
  router.post(route('words.favourite', props.word.slug), {}, {
    preserveScroll: true,
    preserveState: true,
  })
}

const playAudio = () => {
  if (audioPlayer.value) {
    playing.value = true
    audioPlayer.value.play()
  }
}

const shareUrl = computed(() => window.location.href)
const shareText = computed(() => `Check out this possible word: ${props.word.text}`)

const shareWhatsApp = () => {
  window.open(`https://wa.me/?text=${encodeURIComponent(shareText.value + '\n' + shareUrl.value)}`, '_blank', 'noopener,noreferrer')
}

const shareFacebook = () => {
  window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl.value)}`, '_blank', 'noopener,noreferrer')
}

const shareTwitter = () => {
  window.open(`https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText.value)}&url=${encodeURIComponent(shareUrl.value)}`, '_blank', 'noopener,noreferrer')
}

const shareLinkedIn = () => {
  window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(shareUrl.value)}`, '_blank', 'noopener,noreferrer')
}

const definitionText = ref('')
const submitting = ref(false)

const definitions = ref(props.word.definitions.map(d => ({
  ...d,
  votes: d.votes ?? [],
})))

watch(() => props.word, (newWord) => {
  definitions.value = newWord.definitions.map(d => ({
    ...d,
    votes: d.votes ?? [],
  }))
}, { deep: true })

const animatingId = ref(null)

const likedByUser = (definition) => {
  return definition.votes.some(v => v.user_id === page.props.auth.user?.id)
}

const submitDefinition = () => {
  if (!definitionText.value.trim()) return

  submitting.value = true
  router.post(route('words.definitions.store', props.word.slug), {
    text: definitionText.value
  }, {
    onFinish: () => {
      submitting.value = false
      definitionText.value = ''
    }
  })
}

const vote = (definitionId) => {
  const def = definitions.value.find(d => d.id === definitionId)
  if (!def) return

  animatingId.value = definitionId
  setTimeout(() => { animatingId.value = null }, 200)

  const userId = page.props.auth?.user?.id
  const liked = def.votes.some(v => v.user_id === userId)

  if (liked) {
    def.votes = def.votes.filter(v => v.user_id !== userId)
    def.votes_count = Math.max(0, def.votes_count - 1)
  } else {
    def.votes.push({ user_id: userId, definition_id: definitionId })
    def.votes_count++
  }

  router.post(route('definitions.vote', definitionId), {}, {
    preserveScroll: true,
    preserveState: true,
  })
}

const login = () => {
  window.location.href = route('auth.google')
}

const echoChannels = []

onMounted(() => {
  props.word.definitions.forEach(def => {
    const channel = window.Echo.channel(`definition.${def.id}`)
    channel.listen('.definition.voted', (e) => {
      const defToUpdate = definitions.value.find(d => d.id === e.definitionId)
      if (defToUpdate) {
        defToUpdate.votes_count = e.votesCount
        if (e.userId === page.props.auth?.user?.id) {
          const existingIndex = defToUpdate.votes.findIndex(v => v.user_id === e.userId)
          if (e.liked) {
            if (existingIndex === -1) {
              defToUpdate.votes.push({
                user_id: e.userId,
                definition_id: e.definitionId,
              })
            }
          } else {
            if (existingIndex !== -1) {
              defToUpdate.votes.splice(existingIndex, 1)
            }
          }
        }
      }
    })
    echoChannels.push(channel)
  })

  const defChannel = window.Echo.channel(`word.${props.word.id}.definitions`)
  defChannel.listen('.definition.created', (e) => {
    if (!definitions.value.find(d => d.id === e.definition.id)) {
      definitions.value.push({
        ...e.definition,
        votes: e.definition.votes ?? [],
      })
    }
  })
  echoChannels.push(defChannel)
})

onBeforeUnmount(() => {
  echoChannels.forEach(ch => {
    window.Echo.leaveChannel(ch.name)
  })
})
</script>
