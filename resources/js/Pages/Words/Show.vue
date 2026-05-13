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
      </div>

      <!-- Definitions Section -->
      <div class="bg-white rounded-lg shadow-sm border p-6 mb-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Definitions</h2>
        
        <div v-if="word.definitions.length > 0" class="space-y-4">
          <div v-for="definition in word.definitions" :key="definition.id" class="border rounded-lg p-4">
            <div class="flex justify-between items-start mb-2">
              <p class="text-gray-800">{{ definition.text }}</p>
              <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-500">{{ definition.votes_count }} votes</span>
                <div v-if="$page.props.auth.user" class="flex space-x-1">
                  <n-button
                    size="small"
                    :type="definition.votes.find(v => v.user_id === $page.props.auth.user.id && v.value === 1) ? 'primary' : 'default'"
                    @click="vote(definition.id, 1)"
                  >
                    ↑
                  </n-button>
                  <n-button
                    size="small"
                    :type="definition.votes.find(v => v.user_id === $page.props.auth.user.id && v.value === -1) ? 'error' : 'default'"
                    @click="vote(definition.id, -1)"
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
        <n-button type="primary" @click="login">
          Login with Google
        </n-button>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Layout from '@/Components/Layout.vue'

const props = defineProps({
  word: Object,
})

const definitionText = ref('')
const submitting = ref(false)

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
</script>
