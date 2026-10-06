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

const naiveUiStub = {
  render() {
    return h('div', Object.values(this.$slots).flatMap((slot) => slot?.() ?? []))
  },
}

const naiveUiComponents = ['n-avatar', 'n-button', 'n-input', 'n-popconfirm', 'n-select', 'n-tooltip']

// The file mounts Show through a single helper so the component is defined
// only once (vue/one-component-per-file).
function mountShow(word) {
  const target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)

  const app = createApp(Show, { word })
  app.config.globalProperties.$page = inertia.page
  naiveUiComponents.forEach((component) => {
    app.component(component, naiveUiStub)
  })
  app.mount(target)

  return { app, target }
}

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
    const mounted = mountShow({
      id: 42,
      text: 'blorg',
      slug: 'blorg',
      syllables: 1,
      definitions: [],
    })
    app = mounted.app

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

    expect(mounted.target.textContent).toContain('To move with purpose')
    expect(mounted.target.textContent).toContain('Verb')
  })
})

describe('register link for a free .com', () => {
  let app
  let openSpy

  const mountWithDomain = (domainStatus) => {
    const mounted = mountShow({
      id: 42,
      text: 'blorg',
      slug: 'blorg',
      syllables: 1,
      definitions: [],
      domain_status: domainStatus,
    })
    app = mounted.app

    return mounted.target
  }

  const registerLink = (target) => target.querySelector('a[href*="namecheap.com"]')

  beforeEach(() => {
    openSpy = vi.fn()
    window.open = openSpy
    window.Echo = {
      channel: vi.fn(() => ({ name: 'stub', listen: vi.fn(() => ({ name: 'stub' })) })),
      leaveChannel: vi.fn(),
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
    delete window.open
  })

  it('renders a static Namecheap anchor when the .com is available', () => {
    const target = mountWithDomain('available')

    const link = registerLink(target)
    expect(link).not.toBeNull()
    expect(link.getAttribute('href')).toBe(
      'https://www.namecheap.com/domains/registration/results/?domain=blorg.com',
    )
    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
    expect(link.textContent.trim()).toBe('Register blorg.com')
  })

  it.each([
    ['taken', 'taken'],
    ['unchecked', 'unchecked'],
    ['check failed', 'check_failed'],
    ['absent verdict', undefined],
  ])('renders no register link when the domain is %s', (_label, domainStatus) => {
    const target = mountWithDomain(domainStatus)

    expect(registerLink(target)).toBeNull()
  })

  it('never opens a popup when the page mounts', () => {
    mountWithDomain('available')

    expect(openSpy).not.toHaveBeenCalled()
  })
})
