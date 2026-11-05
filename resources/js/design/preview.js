import { computed, ref, watch } from 'vue'
import { darkTheme } from 'naive-ui'

export const directions = [
  { id: 'lab', name: 'Word Lab', number: '01', description: 'A little strange. A lot of possibility.' },
  { id: 'folio', name: 'The Unwritten', number: '02', description: 'An unfinished dictionary. An open invitation.' },
  { id: 'play', name: 'Word Playground', number: '03', description: 'Good words. Weird words. Your words.' },
]
const read = (key) => { try { return window.localStorage.getItem(key) } catch { return null } }
const requested = new window.URLSearchParams(window.location.search).get('design')
export const design = ref(directions.some(d => d.id === requested) ? requested : directions.some(d => d.id === read('pw-design')) ? read('pw-design') : 'lab')
export const mode = ref(read('pw-mode') === 'light' ? 'light' : 'dark')
export const direction = computed(() => directions.find(d => d.id === design.value))
export const theme = computed(() => mode.value === 'dark' ? darkTheme : null)
const palettes = { lab: ['#bafd64', '#466b17'], folio: ['#efac79', '#994718'], play: ['#c5b4ff', '#6344b8'] }
export const overrides = computed(() => ({ common: {
  primaryColor: palettes[design.value][mode.value === 'dark' ? 0 : 1],
  primaryColorHover: palettes[design.value][mode.value === 'dark' ? 0 : 1],
  primaryColorPressed: palettes[design.value][mode.value === 'dark' ? 0 : 1],
  borderRadius: design.value === 'folio' ? '2px' : design.value === 'play' ? '18px' : '12px',
  fontFamily: 'inherit',
  bodyColor: mode.value === 'dark' ? '#15171b' : '#faf8f2',
}, Card: { color: 'var(--surface)', borderColor: 'var(--line)', titleTextColor: 'var(--ink)', textColor: 'var(--muted)' }, Input: { color: 'var(--surface)', textColor: 'var(--ink)', border: '1px solid var(--line)', heightMedium: '46px' } }))
watch([design, mode], () => {
  const root = window.document.documentElement
  root.dataset.design = design.value
  root.dataset.mode = mode.value
  root.style.colorScheme = mode.value
  try { window.localStorage.setItem('pw-design', design.value); window.localStorage.setItem('pw-mode', mode.value) } catch { /* Preview still works without storage. */ }
}, { immediate: true })
