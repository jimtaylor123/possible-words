<template>
  <n-avatar
    round
    :theme-overrides="{ color: '#c5b4ff', textColor: '#241544' }"
    :size="size"
    :src="user?.avatar || undefined"
    :alt="user?.name || 'Deleted user'"
    :img-props="{ referrerPolicy: 'no-referrer' }"
    class="definition-avatar shrink-0"
  >
    <template v-if="!user?.avatar">{{ initials }}</template>
    <template #fallback>{{ initials }}</template>
  </n-avatar>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  user: { type: Object, default: null },
  origin: { type: String, default: null },
  size: { type: Number, default: 28 },
})
const initials = computed(() => props.origin === 'ai' ? 'AI' : (props.user?.name || '?').trim().split(/\s+/).slice(0, 2).map(part => part.charAt(0).toUpperCase()).join(''))
</script>
