import { computed, ref, watch } from 'vue'
import { darkTheme } from 'naive-ui'

const read = (key) => { try { return window.localStorage.getItem(key) } catch { return null } }
export const mode = ref(read('pw-mode') === 'light' ? 'light' : 'dark')
export const theme = computed(() => mode.value === 'dark' ? darkTheme : null)
export const overrides = computed(() => ({ common: {
  primaryColor: mode.value === 'dark' ? '#c5b4ff' : '#6344b8',
  primaryColorHover: mode.value === 'dark' ? '#c5b4ff' : '#6344b8',
  primaryColorPressed: mode.value === 'dark' ? '#c5b4ff' : '#6344b8',
  borderRadius: '12px',
  fontFamily: 'inherit',
  bodyColor: mode.value === 'dark' ? '#15171b' : '#faf8f2',
}, Card: { color: 'var(--surface)', borderColor: 'var(--line)', titleTextColor: 'var(--ink)', textColor: 'var(--muted)' }, Input: { color: 'var(--surface)', textColor: 'var(--ink)', border: '1px solid var(--line)', heightMedium: '46px' } }))
watch(mode, () => {
  const root = window.document.documentElement
  root.dataset.design = 'lab'
  root.dataset.mode = mode.value
  root.style.colorScheme = mode.value
  try { window.localStorage.setItem('pw-mode', mode.value) } catch { /* Theme switching still works without storage. */ }
}, { immediate: true })
