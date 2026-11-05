<template>
  <button type="button" class="text-xs text-gray-500 hover:text-gray-900 underline-offset-4 hover:underline" aria-haspopup="dialog" @click="open = true">shortcuts</button>
  <n-modal v-model:show="open" preset="card" title="Keyboard shortcuts" class="w-[360px] max-w-[calc(100vw-32px)]" :bordered="true">
    <section aria-labelledby="browse-shortcuts">
      <h3 id="browse-shortcuts" class="mb-3 text-sm font-semibold text-gray-900">Main word list</h3>
      <dl class="space-y-3 text-sm">
        <div class="flex items-center justify-between gap-4"><dt>Focus search</dt><dd><kbd class="shortcut-key">s</kbd></dd></div>
        <div class="flex items-center justify-between gap-4"><dt>Show / hide filters</dt><dd><kbd class="shortcut-key">f</kbd></dd></div>
      </dl>
    </section>
    <section class="mt-6" aria-labelledby="word-shortcuts">
      <h3 id="word-shortcuts" class="mb-3 text-sm font-semibold text-gray-900">Word details</h3>
      <dl class="space-y-3 text-sm">
        <div class="flex items-center justify-between gap-4"><dt>Play pronunciation <span class="block text-xs text-gray-500">When audio is available</span></dt><dd><kbd class="shortcut-key">p</kbd></dd></div>
        <div class="flex items-center justify-between gap-4"><dt>Add / remove favourite <span class="block text-xs text-gray-500">Sign-in required</span></dt><dd><kbd class="shortcut-key">l</kbd></dd></div>
        <div class="flex items-center justify-between gap-4"><dt>Add a definition <span class="block text-xs text-gray-500">Sign-in required</span></dt><dd><kbd class="shortcut-key">+</kbd></dd></div>
      </dl>
    </section>
    <p class="mt-6 border-t pt-4 text-xs leading-relaxed text-gray-500">Shortcuts pause while you’re typing or a dialog is open. Press <kbd>Esc</kbd> to close a dialog.</p>
  </n-modal>
</template>

<script setup>
import { ref } from 'vue'
const open = ref(false)
</script>
