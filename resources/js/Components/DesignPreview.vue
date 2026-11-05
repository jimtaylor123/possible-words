<template>
  <aside class="design-preview" aria-label="Design preview controls">
    <div class="preview-label"><span class="preview-dot" /> DESIGN EXPLORATIONS <span class="preview-hint">Pick your possible.</span></div>
    <div class="preview-options">
      <button v-for="item in directions" :key="item.id" type="button" :aria-pressed="design === item.id" @click="design = item.id">
        <span>{{ item.number }}</span> {{ item.name }}
      </button>
      <button type="button" class="mode-toggle" :aria-label="`Switch to ${mode === 'dark' ? 'light' : 'dark'} mode`" @click="mode = mode === 'dark' ? 'light' : 'dark'">{{ mode === 'dark' ? '☀ Light' : '☾ Dark' }}</button>
    </div>
  </aside>
</template>

<script setup>
import { design, mode, directions } from '@/design/preview'
</script>
