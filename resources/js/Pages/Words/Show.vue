<template>
  <Layout>
    <div class="max-w-6xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <!-- Word Header -->
      <div class="text-center mb-8">
        <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ word.text }}</h1>
        <div class="flex justify-center space-x-4 text-gray-500">
          <span>{{ word.syllables }} syllable{{ word.syllables !== 1 ? 's' : '' }}</span>
          <span>{{ word.text.length }} letters</span>
        </div>
        <div v-if="word.ipa" class="flex items-center justify-center gap-3 mt-3">
          <span class="text-lg text-neutral-500 font-mono">{{ word.ipa }}</span>
          <n-button
            v-if="word.audio_url"
            size="small"
            @click="playAudio"
          >
            {{ playing ? 'Playing...' : 'Play' }}
          </n-button>
          <audio ref="audioPlayer" :src="word.audio_url" @ended="playing = false" @error="playing = false" />
        </div>
      </div>

      <!-- Two-Column Layout -->
      <div class="flex gap-6">
        <!-- Left Column: Definitions -->
        <div class="w-1/2 space-y-4">
          <h2 class="text-xl font-bold text-gray-900">Definitions</h2>

          <div v-if="definitions.length > 0" class="space-y-3">
            <div
              v-for="definition in definitions"
              :key="definition.id"
              class="border rounded-lg p-4 cursor-pointer transition-colors"
              :class="selectedDefinition?.id === definition.id ? 'ring-2 ring-blue-500 border-blue-500' : 'hover:border-gray-300'"
              @click="selectDefinition(definition)"
            >
              <div class="flex justify-between items-start mb-2">
                <p class="text-gray-800">{{ definition.text }}</p>
                <div class="flex items-center space-x-2 shrink-0">
                  <span class="text-sm text-gray-500">{{ definition.votes_count }} votes</span>
                  <div v-if="$page.props.auth.user" class="flex space-x-1">
                    <n-button
                      size="small"
                      :type="isOwnDefinition(definition) || definition.votes.find(v => v.user_id === $page.props.auth.user.id && v.value === 1) ? 'primary' : 'default'"
                      :disabled="isOwnDefinition(definition)"
                      @click.stop="vote(definition.id, 1)"
                    >
                      ↑
                    </n-button>
                    <n-button
                      size="small"
                      :type="definition.votes.find(v => v.user_id === $page.props.auth.user.id && v.value === -1) ? 'error' : 'default'"
                      :disabled="isOwnDefinition(definition)"
                      @click.stop="vote(definition.id, -1)"
                    >
                      ↓
                    </n-button>
                  </div>
                </div>
              </div>
              <div class="text-sm text-gray-500">
                by {{ definition.user.name }}
              </div>
            </div>
          </div>

          <div v-else class="text-gray-400 italic text-center py-8 bg-white rounded-lg border">
            No definitions yet. Be the first to define this word!
          </div>

          <!-- Add Definition Form -->
          <div v-if="$page.props.auth.user" class="bg-white rounded-lg shadow-sm border p-4">
            <h3 class="text-lg font-bold text-gray-900 mb-3">Add a Definition</h3>
            <n-input
              v-model:value="definitionText"
              type="textarea"
              placeholder="What does this word mean?"
              :rows="2"
              class="mb-3"
            />
            <n-button type="primary" :loading="submitting" @click="submitDefinition">
              Submit Definition
            </n-button>
          </div>

          <div v-else class="bg-blue-50 rounded-lg border border-blue-200 p-4 text-center">
            <p class="text-blue-800 mb-3">Want to add a definition or vote?</p>
            <div class="flex justify-center">
              <GoogleSignInButton @click="login" />
            </div>
          </div>
        </div>

        <!-- Right Column: Comments -->
        <div class="w-1/2">
          <div v-if="selectedDefinition" class="bg-white rounded-lg shadow-sm border p-4">
            <h3 class="text-lg font-bold text-gray-900 mb-1">Comments</h3>
            <p class="text-sm text-gray-500 mb-4">{{ selectedDefinition.text }}</p>
            <CommentThread
              :key="selectedDefinition.id"
              :definition="selectedDefinition"
              :comments="selectedDefinition.comments ?? []"
              @refetch="refreshComments"
            />
          </div>
          <div v-else class="bg-gray-50 rounded-lg border p-4 text-center text-gray-400 italic">
            Select a definition to view its comments
          </div>
        </div>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { ref, onMounted, onBeforeUnmount } from 'vue'
import Layout from '@/Components/Layout.vue'
import GoogleSignInButton from '@/Components/GoogleSignInButton.vue'
import CommentThread from '@/Components/CommentThread.vue'

const props = defineProps({
  word: { type: Object, required: true },
})

const page = usePage()
const playing = ref(false)
const audioPlayer = ref(null)

const playAudio = () => {
  if (audioPlayer.value) {
    playing.value = true
    audioPlayer.value.play()
  }
}

const definitionText = ref('')
const submitting = ref(false)

const definitions = ref(props.word.definitions.map(d => ({
  ...d,
  votes: d.votes ?? [],
  comments: d.comments ?? [],
})))

const selectedDefinition = ref(definitions.value[0] ?? null)

const selectDefinition = (definition) => {
  selectedDefinition.value = definition
}

const isOwnDefinition = (definition) => {
  return definition.user_id === page.props.auth.user?.id
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

const vote = (definitionId, value) => {
  router.post(route('definitions.vote', definitionId), {
    value: value
  }, {
    preserveScroll: true
  })
}

const login = () => {
  window.location.href = route('auth.google')
}

const refreshComments = () => {
  router.reload({ only: ['word'], preserveScroll: true })
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
          const existingVote = defToUpdate.votes.find(v => v.user_id === e.userId)
          if (existingVote) {
            existingVote.value = e.voteValue
          } else {
            defToUpdate.votes.push({
              user_id: e.userId,
              definition_id: e.definitionId,
              value: e.voteValue
            })
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
        comments: [],
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
