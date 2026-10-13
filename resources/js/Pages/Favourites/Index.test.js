import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Favourites from './Index.vue'

const inertia = vi.hoisted(() => ({
  router: { get: vi.fn(), post: vi.fn() },
  page: { props: { auth: { favourite_ids: [] } } },
}))

vi.mock('@inertiajs/vue3', () => ({
  router: inertia.router,
  Link: { render() { return h('a', this.$slots.default?.()) } },
  usePage: () => inertia.page,
}))

vi.mock('@/Components/Layout.vue', async () => {
  const { h } = await import('vue')

  return { default: { render() { return h('div', this.$slots.default?.()) } } }
})

const componentStub = {
  render() {
    return h('div', this.$attrs, Object.values(this.$slots).flatMap((slot) => slot?.() ?? []))
  },
}

const buttonStub = {
  render() {
    return h('button', this.$attrs, this.$slots.default?.())
  },
}

const paginationStub = {
  emits: ['update:page'],
  render() {
    return h('button', { 'data-page': '2', onClick: () => this.$emit('update:page', 2) }, 'Page 2')
  },
}

function mountFavourites(isPopular = false) {
  const target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)
  const app = createApp(Favourites, {
    isPopular,
    words: {
      data: [{ id: 1, text: 'blorg', slug: 'blorg', syllables: 1, definitions: [], definitions_count: 0, favourites_count: 3 }],
      current_page: 1,
      last_page: 2,
    },
  })
  app.config.globalProperties.$page = inertia.page
  app.config.globalProperties.route = globalThis.route
  const naiveUiComponents = ['n-avatar', 'n-button', 'n-card', 'n-pagination', 'n-tooltip']
  naiveUiComponents.forEach((component) => {
    app.component(component, component === 'n-pagination' ? paginationStub : component === 'n-button' ? buttonStub : componentStub)
  })
  app.mount(target)

  return { app, target }
}

describe('favourites lists', () => {
  let app

  beforeEach(() => {
    globalThis.route = vi.fn((name) => `/${name}`)
    inertia.page.props.auth = { user: null, favourite_ids: [] }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete globalThis.route
    vi.clearAllMocks()
  })

  it('shows favourite totals in the public popular list', () => {
    const mounted = mountFavourites(true)
    app = mounted.app

    expect(mounted.target.querySelector('h1').textContent).toBe('Most Favourited Words')
    expect(mounted.target.textContent).toContain('3 favourites')
  })

  it('keeps popular pagination on the public ranking route', async () => {
    const mounted = mountFavourites(true)
    app = mounted.app

    mounted.target.querySelector('[data-page="2"]').click()
    await nextTick()

    expect(inertia.router.get).toHaveBeenLastCalledWith('/words.popular', { page: 2 }, {
      preserveScroll: true,
      preserveState: true,
    })
  })

  it('toggles a favourite without following the popular word card link', () => {
    inertia.page.props.auth = { user: { id: 1, name: 'Reader' }, favourite_ids: [] }
    const mounted = mountFavourites(true)
    app = mounted.app
    const button = mounted.target.querySelector('[aria-label="Add to favourites"]')
    const event = new window.MouseEvent('click', { bubbles: true, cancelable: true })

    expect(button.dispatchEvent(event)).toBe(false)
    expect(inertia.router.post).toHaveBeenCalledWith('/words.favourite', {}, expect.objectContaining({
      preserveScroll: true,
      preserveState: true,
    }))
  })
})
