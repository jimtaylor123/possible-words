import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const inertia = vi.hoisted(() => ({
  page: {
    props: {
      auth: { user: null, favourite_ids: [] },
      flash: {},
    },
  },
}))

vi.mock('@inertiajs/vue3', () => ({
  router: { get: vi.fn(), post: vi.fn() },
  usePage: () => inertia.page,
}))

vi.mock('@/Components/Layout.vue', async () => {
  const { h } = await import('vue')

  return {
    default: {
      render() {
        return h('div', this.$slots.default?.())
      },
    },
  }
})

describe('word definition realtime updates', () => {
  let app
  let listeners

  beforeEach(() => {
    listeners = {}
    window.Echo = {
      channel: vi.fn((name) => {
        const channel = {
          name,
          listen: vi.fn((event, listener) => {
            listeners[`${name}:${event}`] = listener

            return channel
          }),
        }

        return channel
      }),
      leaveChannel: vi.fn(),
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
  })

  it('renders the category from a received definition.created payload', async () => {
    const target = globalThis.document.createElement('div')
    globalThis.document.body.appendChild(target)
    app = createApp(Show, {
      word: {
        id: 42,
        text: 'blorg',
        slug: 'blorg',
        syllables: 1,
        definitions: [],
      },
    })
    app.config.globalProperties.$page = inertia.page
    const naiveUiStub = {
      render() {
        return h('div', Object.values(this.$slots).flatMap((slot) => slot?.() ?? []))
      },
    }
    const naiveUiComponents = ['n-avatar', 'n-button', 'n-input', 'n-popconfirm', 'n-select', 'n-tooltip']
    naiveUiComponents.forEach((component) => {
      app.component(component, naiveUiStub)
    })
    app.mount(target)

    listeners['word.42.definitions:.definition.created']({
      definition: {
        id: 7,
        text: 'To move with purpose',
        part_of_speech: 'verb',
        votes_count: 0,
        user: { id: 3, name: 'Receiving User', avatar: null },
      },
    })
    await nextTick()

    expect(target.textContent).toContain('To move with purpose')
    expect(target.textContent).toContain('Verb')
  })
})
