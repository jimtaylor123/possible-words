<template>
  <button
    type="button"
    class="inline-flex items-center justify-center gap-3 rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-900 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 hover:shadow active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
    :aria-label="label"
    @click="$emit('click')"
  >
    <span class="flex h-[18px] w-[18px] shrink-0 items-center justify-center" aria-hidden="true">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none">
        <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z" />
        <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.909-2.258c-.806.54-1.843.86-3.047.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" />
        <path fill="#FBBC05" d="M3.964 10.711c-.18-.54-.282-1.117-.282-1.711s.102-1.17.282-1.711V4.957H.957C.348 6.174 0 7.55 0 9s.348 2.826.957 4.043l3.007-2.332z" />
        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.957L3.964 7.289C4.672 5.163 6.656 3.58 9 3.58z" />
      </svg>
    </span>
    <span>{{ label }}</span>
  </button>
</template>

<script setup>
defineProps({
  label: {
    type: String,
    default: 'Continue with Google',
  },
})

defineEmits(['click'])
</script>
