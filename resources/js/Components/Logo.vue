<template>
  <Link :href="route('home')" class="flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 64 64" class="shrink-0">
      <rect width="64" height="64" rx="14" fill="#0891b2" />
      <text
        x="32"
        y="43"
        font-family="system-ui, -apple-system, sans-serif"
        font-size="34"
        font-weight="700"
        fill="white"
        text-anchor="middle"
      >[P]</text>
    </svg>
    <div class="flex flex-col leading-tight">
      <span class="text-lg font-bold text-gray-900">Possible</span>
      <span class="text-sm font-medium text-gray-500 -mt-0.5">Words</span>
    </div>
  </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
</script>
