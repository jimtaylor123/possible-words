<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center">
            <Logo />
          </div>

          <div class="flex items-center gap-4 sm:gap-6">
            <Link :href="route('about')" class="text-gray-600 hover:text-gray-900 text-sm sm:text-base">
              About
            </Link>
            <Link
              v-if="user"
              :href="route('words.favourites')"
              class="text-gray-600 hover:text-gray-900 text-sm sm:text-base"
            >
              Favourites
            </Link>

            <n-dropdown
              v-if="user"
              trigger="click"
              placement="bottom-end"
              :options="userMenuOptions"
              :show-arrow="true"
              @select="handleUserMenuSelect"
            >
              <button
                type="button"
                class="flex items-center gap-2 rounded-full p-0.5 ring-2 ring-transparent hover:ring-blue-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 transition"
                :aria-label="`Account menu for ${user.name}`"
              >
                <n-avatar
                  round
                  size="medium"
                  :src="avatarSrc"
                  :alt="user.name"
                  class="shadow-sm"
                  :img-props="{ referrerPolicy: 'no-referrer' }"
                >
                  <template v-if="!avatarSrc">
                    {{ userInitials }}
                  </template>
                  <template #fallback>
                    {{ userInitials }}
                  </template>
                </n-avatar>
                <span class="hidden sm:inline text-sm font-medium text-gray-700 max-w-[140px] truncate">
                  {{ user.name }}
                </span>
                <svg class="hidden sm:block w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>
            </n-dropdown>

            <GoogleSignInButton v-else @click="login" />
          </div>
        </div>
      </div>
    </nav>

    <div
      v-if="flash.error"
      class="bg-red-50 border-b border-red-200 text-red-800 text-sm text-center py-2.5 px-4"
      role="alert"
    >
      {{ flash.error }}
    </div>
    <div
      v-else-if="flash.success"
      class="bg-emerald-50 border-b border-emerald-200 text-emerald-900 text-sm text-center py-2.5 px-4"
      role="status"
    >
      {{ flash.success }}
    </div>

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
import { computed, h } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import GoogleSignInButton from '@/Components/GoogleSignInButton.vue'
import Logo from '@/Components/Logo.vue'

const page = usePage()

const flash = computed(() => page.props.flash ?? {})

const user = computed(() => page.props.auth?.user ?? null)

const avatarSrc = computed(() => {
  const url = user.value?.avatar
  return url && String(url).trim() !== '' ? url : undefined
})

const userInitials = computed(() => {
  const name = user.value?.name
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return name.slice(0, 2).toUpperCase()
})

const userMenuOptions = computed(() => {
  const u = user.value
  if (!u) return []

  const headerChildren = [
    h('p', { class: 'text-sm font-semibold text-gray-900 truncate' }, u.name),
  ]
  if (u.email) {
    headerChildren.push(
      h('p', { class: 'text-xs text-gray-500 truncate mt-0.5' }, u.email),
    )
  }

  return [
    {
      key: 'profile-header',
      type: 'render',
      render: () =>
        h(
          'div',
          {
            class: 'px-1 py-1 select-none cursor-default',
          },
          [
            h(
              'div',
              { class: 'rounded-md bg-gray-50 px-3 py-2.5 border border-gray-100' },
              headerChildren,
            ),
          ],
        ),
    },
    ...(u.is_admin
      ? [
          {
            label: 'Admin',
            key: 'admin',
            props: { class: 'font-medium' },
          },
          { type: 'divider', key: 'd2' },
        ]
      : []),
    { type: 'divider', key: 'd1' },
    {
      label: 'Log out',
      key: 'logout',
      props: { class: 'text-red-600 font-medium' },
    },
  ]
})

const handleUserMenuSelect = (key) => {
  if (key === 'logout') {
    logout()
  } else if (key === 'admin') {
    router.get(route('admin.dashboard'))
  }
}

const login = () => {
  window.location.href = route('auth.google')
}

const logout = () => {
  router.post(route('logout'))
}
</script>
