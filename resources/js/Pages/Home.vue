<template>
  <Layout>
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
      <!-- Hero Section -->
      <div class="text-center mb-12">
        <h1 class="text-4xl font-bold text-gray-900 mb-4">
          Discover Available Words
        </h1>
        <p class="text-xl text-gray-600 mb-8">
          Find pronounceable words that aren't in the dictionary yet and help define them
        </p>
        <Link :href="route('words.index')" class="inline-block">
          <n-button type="primary" size="large">
            Browse Words
          </n-button>
        </Link>
      </div>

      <!-- Featured Words -->
      <div class="mb-12">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Featured Words</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <a
            v-for="word in featuredWords"
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
              <div class="space-y-2">
                <div class="text-sm text-gray-500">
                  {{ word.syllables }} syllable{{ word.syllables !== 1 ? 's' : '' }}
                </div>
                <div v-if="word.definitions.length > 0" class="text-gray-700">
                  "{{ word.definitions[0].text }}"
                </div>
                <div v-else class="text-gray-400 italic">
                  No definitions yet
                </div>
              </div>
            </n-card>
          </a>
        </div>
      </div>

      <!-- Recent Words -->
      <div>
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Recent Words</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
          <a
            v-for="word in recentWords"
            :key="word.id"
            :href="route('words.show', word.slug)"
            class="group block rounded-lg no-underline text-inherit focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
          >
            <n-card class="hover:shadow-lg transition-shadow h-full">
              <template #header>
                <span class="text-lg font-semibold text-blue-600 group-hover:text-blue-800">
                  {{ word.text }}
                </span>
              </template>
              <div class="text-sm text-gray-500">
                {{ word.syllables }} syllable{{ word.syllables !== 1 ? 's' : '' }}
              </div>
            </n-card>
          </a>
        </div>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import Layout from '@/Components/Layout.vue'

defineProps({
  featuredWords: Array,
  recentWords: Array,
})
</script>
