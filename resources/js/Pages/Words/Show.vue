<template>
  <Layout>
    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
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
            {{ playing ? 'Playing...' : '🔊 Play' }}
          </n-button>
          <audio ref="audioPlayer" :src="word.audio_url" @ended="playing = false" @error="playing = false" />
        </div>
      </div>

      <!-- Definitions Section -->
      <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Definitions</h2>
        
        <div v-if="definitions.length > 0" class="space-y-4">
          <div v-for="definition in definitions" :key="definition.id" class="border rounded-lg p-4">
            <div class="flex justify-between items-start mb-2">
              <p class="text-gray-800">{{ definition.text }}</p>
              <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-500">{{ definition.votes_count }} votes</span>
                <div v-if="$page.props.auth.user" class="flex space-x-1">
                  <n-button
                    size="small"
                    :type="isOwnDefinition(definition) || definition.votes.find(v => v.user_id === $page.props.auth.user.id && v.value === 1) ? 'primary' : 'default'"
                    :disabled="isOwnDefinition(definition)"
                    @click="vote(definition.id, 1)"
                  >
                    ↑
                  </n-button>
                  <n-button
                    :style="{ visibility: isOwnDefinition(definition) ? 'hidden' : 'visible' }"
                    size="small"
                    :type="definition.votes.find(v => v.user_id === $page.props.auth.user.id && v.value === -1) ? 'error' : 'default'"
                    @click="vote(definition.id, -1)"
                  >
                    ↓
                  </n-button>
                </div>
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
import { ref, onMounted, onBeforeUnmount } from 'vue'
import Layout from '@/Components/Layout.vue'
import GoogleSignInButton from '@/Components/GoogleSignInButton.vue'

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
})))

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
