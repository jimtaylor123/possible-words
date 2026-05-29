<template>
  <Layout>
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <!-- Header -->
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">Browse Words</h1>
        
        <!-- Search and Filters -->
        <div class="bg-white p-6 rounded-lg shadow-sm border">
          <form class="space-y-4" @submit.prevent="search">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <n-input
                v-model:value="filters.search"
                placeholder="Search words..."
                clearable
              />
              
              <n-select
                v-model:value="filters.syllables"
                placeholder="Syllables"
                clearable
                :options="syllableOptions"
              />
              
              <n-select
                v-model:value="filters.length"
                placeholder="Length"
                clearable
                :options="lengthOptions"
              />
              
              <n-input
                v-model:value="filters.starts_with"
                placeholder="Starts with..."
                clearable
              />
            </div>
            
            <div class="flex space-x-4">
              <n-button type="primary" @click="search">
                Search
              </n-button>
              <n-button @click="clearFilters">
                Clear
              </n-button>
            </div>
          </form>
        </div>
      </div>

      <!-- Words Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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
                    by {{ definition.user.name }} • {{ definition.votes_count }} votes
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

      <!-- Pagination -->
      <div class="mt-8 flex justify-center">
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
import { ref } from 'vue'
import Layout from '@/Components/Layout.vue'

const props = defineProps({
  words: { type: Object, required: true },
  filters: { type: Object, required: true },
})

const filters = ref({ ...props.filters })
const currentPage = ref(props.words.current_page)

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

const search = () => {
  router.get(route('words.index'), filters.value, {
    preserveState: true,
    replace: true,
  })
}

const clearFilters = () => {
  filters.value = {}
  search()
}

const handlePageChange = (page) => {
  router.get(route('words.index'), { ...filters.value, page }, {
    preserveState: true,
  })
}
</script>
