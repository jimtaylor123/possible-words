import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Home from './Home.vue'

const inertia = vi.hoisted(() => ({
  router: { get: vi.fn(), post: vi.fn(), visit: vi.fn() },
  page: { props: { auth: { user: null, favourite_ids: [] } } },
}))

vi.mock('@inertiajs/vue3', () => ({
  router: inertia.router,
  usePage: () => inertia.page,
}))

vi.mock('@/Components/Layout.vue', async () => {
  const { h } = await import('vue')

  return { default: { render() { return h('div', this.$slots.default?.()) } } }
})

const componentStub = {
  render() {
    return h('div', this.$attrs, this.$slots.default?.())
  },
}

const buttonStub = {
  render() {
    return h('button', this.$attrs, this.$slots.default?.())
  },
}

const words = {
  data: [],
  current_page: 1,
  last_page: 1,
  total: 1234,
}

function mountHome() {
  const target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)

  const app = createApp(Home, {
    words,
    filters: { search: '', definitions: '', sort: 'created_at', direction: 'desc' },
  })
  app.config.globalProperties.$page = inertia.page
  const naiveUiComponents = ['n-auto-complete', 'n-avatar', 'n-button', 'n-card', 'n-input', 'n-select', 'n-spin', 'n-tooltip']
  naiveUiComponents.forEach((component) => {
    app.component(component, component === 'n-button' ? buttonStub : componentStub)
  })
  app.mount(target)

  return { app, target }
}

describe('filter shortcut', () => {
  let app

  beforeEach(() => {
    globalThis.route = vi.fn((name) => `/${name}`)
    globalThis.IntersectionObserver = class {
      observe() {}
      disconnect() {}
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete globalThis.route
    delete globalThis.IntersectionObserver
    vi.clearAllMocks()
  })

  it('toggles advanced filters with f', async () => {
    const mounted = mountHome()
    app = mounted.app

    expect(mounted.target.querySelector('[aria-label="Show filters"]')).not.toBeNull()

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'f' }))
    await nextTick()

    expect(mounted.target.querySelector('[aria-label="Hide filters"]')).not.toBeNull()

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'f' }))
    await nextTick()

    expect(mounted.target.querySelector('[aria-label="Show filters"]')).not.toBeNull()
  })

  it('does not toggle filters while typing in an editable control', () => {
    const mounted = mountHome()
    app = mounted.app
    const input = globalThis.document.createElement('input')
    mounted.target.appendChild(input)

    input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'f', bubbles: true }))

    expect(mounted.target.querySelector('[aria-label="Show filters"]')).not.toBeNull()
  })
})

describe('result count', () => {
  let app

  beforeEach(() => {
    globalThis.route = vi.fn((name) => `/${name}`)
    globalThis.IntersectionObserver = class {
      observe() {}
      disconnect() {}
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete globalThis.route
    delete globalThis.IntersectionObserver
  })

  it('shows the paginator total above the filter control', () => {
    const mounted = mountHome()
    app = mounted.app

    expect(mounted.target.querySelector('[aria-label="Show filters"]')?.parentElement?.textContent).toContain('1234 words')
  })
})
