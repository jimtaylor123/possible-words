<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center">
            <Link :href="route('home')" class="text-2xl font-bold text-gray-900">
              PossibleWords
            </Link>
          </div>
          
          <div class="flex items-center space-x-4">
            <Link :href="route('words.index')" class="text-gray-600 hover:text-gray-900">
              Browse Words
            </Link>
            
            <div v-if="$page.props.auth.user" class="flex items-center space-x-4">
              <span class="text-gray-600">{{ $page.props.auth.user.name }}</span>
              <form @submit.prevent="logout" method="post">
                <n-button type="primary" ghost @click="logout">
                  Logout
                </n-button>
              </form>
            </div>
            
            <div v-else>
              <n-button type="primary" @click="login">
                Login with Google
              </n-button>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <main>
      <slot />
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t mt-12">
      <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <p class="text-center text-gray-500">
          Discover available words and help define them
        </p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'

const login = () => {
  window.location.href = route('auth.google')
}

const logout = () => {
  router.post(route('logout'))
}
</script>
