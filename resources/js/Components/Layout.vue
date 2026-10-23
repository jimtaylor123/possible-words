<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navigation -->
    <nav aria-label="Primary navigation" class="sticky top-0 z-50 bg-white shadow-sm border-b">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 flex-wrap items-center py-2 sm:h-16 sm:flex-nowrap sm:py-0">
          <div class="min-w-0 shrink">
            <div class="sm:hidden">
              <Logo compact />
            </div>
            <div class="hidden sm:block">
              <Logo />
            </div>
          </div>

          <div class="order-3 flex w-full shrink-0 items-center gap-2 pt-2 sm:order-none sm:ml-auto sm:w-auto sm:gap-6 sm:pt-0">
            <Link
              :href="route('home')"
              :class="[navLinkClass('/'), 'sm:hidden']"
              :aria-current="isCurrentPath('/') ? 'page' : undefined"
            >
              Home
            </Link>
            <Link
              :href="route('about')"
              :class="navLinkClass('/about')"
              :aria-current="isCurrentPath('/about') ? 'page' : undefined"
            >
              About
            </Link>
            <Link
              :href="route('words.popular')"
              :class="navLinkClass('/popular')"
              :aria-current="isCurrentPath('/popular') ? 'page' : undefined"
            >
              Popular
            </Link>
            <Link
              v-if="user"
              :href="route('words.favourites')"
              :class="navLinkClass('/favourites')"
              :aria-current="isCurrentPath('/favourites') ? 'page' : undefined"
            >
              Favourites
            </Link>
          </div>
          <div class="ml-auto flex shrink-0 items-center gap-2 sm:ml-6 sm:gap-6">
            <n-popover v-if="user" trigger="click" placement="bottom-end">
              <template #trigger>
                <button type="button" aria-label="Notifications" class="relative p-2 text-gray-600 hover:text-gray-900" @click="unlockNotificationAudio">
                  <svg :class="['h-6 w-6 origin-top', { 'notification-bell-ring': jiggle }]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 01-6 0" />
                  </svg>
                  <span v-if="unreadCount" class="absolute right-0.5 top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
                </button>
              </template>
              <section class="w-[23rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-white" aria-label="Notifications">
                <header class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                  <div>
                    <p class="text-sm font-semibold text-gray-900">Notifications</p>
                    <p class="text-xs text-gray-500">{{ unreadCount ? `${unreadCount} unread` : 'You are all caught up' }}</p>
                  </div>
                  <div class="flex items-center gap-3 text-xs font-medium">
                    <button v-if="unreadCount" type="button" class="text-blue-700 hover:text-blue-900" @click="markAllRead">Read all</button>
                    <button v-if="hasReadNotifications" type="button" class="text-gray-500 hover:text-gray-900" @click="clearRead">Clear read</button>
                  </div>
                </header>
                <div class="max-h-[28rem] overflow-auto p-2">
                  <p v-if="!notifications.length" class="px-3 py-8 text-center text-sm text-gray-500">No notifications yet.</p>
                  <a
                    v-for="notification in notifications"
                    v-else
                    :key="notification.id"
                    :href="notification.data.url || undefined"
                    :class="['group mb-1 flex gap-3 rounded-lg p-3 transition', notification.read_at ? 'text-gray-600 hover:bg-gray-50' : 'bg-blue-50/70 text-gray-900 hover:bg-blue-100/70']"
                    @click.prevent="openNotification(notification)"
                  >
                    <span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm', notificationIconClass(notification.kind)]" aria-hidden="true">{{ notificationIcon(notification.kind) }}</span>
                    <span class="min-w-0 flex-1">
                      <span class="flex items-start gap-2">
                        <span class="min-w-0 flex-1 text-sm font-semibold leading-5">{{ notification.data.title }}</span>
                        <span v-if="!notification.read_at" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-600" aria-label="Unread" />
                      </span>
                      <span class="mt-0.5 block text-sm leading-5 text-gray-600">{{ notification.data.body }}</span>
                      <time :datetime="notification.created_at" :title="formatAbsoluteTime(notification.created_at)" class="mt-1.5 block text-xs text-gray-400">{{ relativeTime(notification.created_at) }}</time>
                    </span>
                  </a>
                </div>
              </section>
            </n-popover>

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

            <GoogleSignInButton v-else mobile-label="Sign in with Google" @click="login" />
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
import { computed, h, onMounted, onUnmounted, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { playNotificationPing, unlockNotificationAudio } from '@/audio/notificationPing'
import GoogleSignInButton from '@/Components/GoogleSignInButton.vue'
import Logo from '@/Components/Logo.vue'

const page = usePage()

const flash = computed(() => page.props.flash ?? {})

const user = computed(() => page.props.auth?.user ?? null)
const notifications = ref(page.props.auth?.notifications?.items ?? [])
const unreadCount = ref(page.props.auth?.notifications?.unread_count ?? 0)
const jiggle = ref(false)
let channel
let jiggleTimeout
const hasReadNotifications = computed(() => notifications.value.some((notification) => notification.read_at))
const applyNotificationPayload = (payload) => {
  notifications.value = payload.notifications
  unreadCount.value = payload.unread_count
}
const markAllRead = () => {
  window.axios.post(route('notifications.read')).then(({ data }) => applyNotificationPayload(data)).catch(() => {})
}
const clearRead = () => {
  window.axios.post(route('notifications.clear-read')).then(({ data }) => applyNotificationPayload(data)).catch(() => {})
}
const markNotificationRead = (notification) => {
  if (notification.read_at) return Promise.resolve()

  return window.axios.post(route('notifications.mark-read', notification.id)).then(({ data }) => applyNotificationPayload(data)).catch(() => {})
}
const openNotification = async (notification) => {
  await markNotificationRead(notification)
  if (notification.data.url) window.location.assign(notification.data.url)
}
const ringBell = () => {
  jiggle.value = false
  window.requestAnimationFrame(() => {
    jiggle.value = true
    clearTimeout(jiggleTimeout)
    jiggleTimeout = setTimeout(() => { jiggle.value = false }, 3000)
  })
}
const received = (event) => {
  notifications.value.unshift({ id: event.id, data: event.data, read_at: null, created_at: event.created_at })
  notifications.value = notifications.value.slice(0, 15)
  unreadCount.value++
  ringBell()
  playNotificationPing()
}
onMounted(() => {
  window.addEventListener('pointerdown', unlockNotificationAudio, { once: true })
  if (user.value && window.Echo) channel = window.Echo.private(`App.Models.User.${user.value.id}`).notification(received)
})
onUnmounted(() => {
  clearTimeout(jiggleTimeout)
  window.removeEventListener('pointerdown', unlockNotificationAudio)
  if (channel && window.Echo) window.Echo.leave(`App.Models.User.${user.value.id}`)
})

const notificationIcon = (kind) => ({
  new_word: 'W',
  definition_liked: '♥',
  admin_report_created: '!',
  report_content_removed: '−',
  report_dismissed: '✓',
}[kind] ?? '•')

const notificationIconClass = (kind) => ({
  new_word: 'bg-violet-100 text-violet-700',
  definition_liked: 'bg-rose-100 text-rose-700',
  admin_report_created: 'bg-amber-100 text-amber-700',
  report_content_removed: 'bg-red-100 text-red-700',
  report_dismissed: 'bg-emerald-100 text-emerald-700',
}[kind] ?? 'bg-gray-100 text-gray-600')

const relativeTime = (timestamp) => {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(timestamp).getTime()) / 1000))
  if (seconds < 60) return 'Just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(new Date(timestamp))
}

const formatAbsoluteTime = (timestamp) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(timestamp))

const currentPath = computed(() => page.url.split('?')[0])

const isCurrentPath = (path) => currentPath.value === path

const navLinkClass = (path) => [
  'rounded-md px-2 py-1 text-sm sm:text-base',
  isCurrentPath(path) ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-600 hover:text-gray-900',
]

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
    {
      label: 'Account',
      key: 'account',
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
  } else if (key === 'account') {
    router.get(route('account.show'))
  }
}

const login = () => {
  window.location.href = route('auth.google')
}

const logout = () => {
  router.post(route('logout'))
}
</script>

<style scoped>
@keyframes notification-bell {
  0%, 100% { transform: rotate(0); }
  10%, 30%, 50%, 70%, 90% { transform: rotate(14deg); }
  20%, 40%, 60%, 80% { transform: rotate(-14deg); }
}

.notification-bell-ring {
  animation: notification-bell 0.5s ease-in-out 6;
}

@media (prefers-reduced-motion: reduce) {
  .notification-bell-ring {
    animation: none;
  }
}
</style>
