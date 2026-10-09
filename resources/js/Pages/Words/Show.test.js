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

vi.mock('@/Components/ReportDialog.vue', async () => {
  const { h } = await import('vue')

  return {
    default: {
      props: ['show', 'targetType', 'target', 'reasonOptions'],
      emits: ['close', 'success'],
      render() {
        if (!this.show) return null

        return h('div', {
          'data-testid': 'definition-report-dialog',
          'data-target-type': this.targetType,
          'data-target': this.target,
        }, this.reasonOptions.map((option) => option.value).join(','))
      },
    },
  }
})

const naiveUiStub = {
  render() {
    return h('div', Object.values(this.$slots).flatMap((slot) => slot?.() ?? []))
  },
}

const buttonStub = {
  render() {
    return h('button', this.$attrs, this.$slots.default?.())
  },
}

const naiveUiComponents = ['n-avatar', 'n-button', 'n-input', 'n-popconfirm', 'n-select', 'n-tooltip']

// The file mounts Show through a single helper so the component is defined
// only once (vue/one-component-per-file).
function mountShow(word, user = null) {
  inertia.page.props.auth = { user, favourite_ids: [] }
  const target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)

  const app = createApp(Show, { word })
  app.config.globalProperties.$page = inertia.page
  naiveUiComponents.forEach((component) => {
    app.component(component, component === 'n-button' ? buttonStub : naiveUiStub)
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

  it('renders the category and example from a received AI definition.created payload', async () => {
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
        origin: 'ai',
        example_sentence: 'They blorged through the busy market.',
        votes_count: 0,
        user: { id: 3, name: 'Receiving User', avatar: null },
      },
    })
    await nextTick()

    expect(mounted.target.textContent).toContain('To move with purpose')
    expect(mounted.target.textContent).toContain('Verb')
    expect(mounted.target.textContent).toContain('Example:')
    expect(mounted.target.textContent).toContain('They blorged through the busy market.')
  })

  it('renders examples only for AI definitions with a non-empty sentence', () => {
    const mounted = mountShow({
      id: 42,
      text: 'blorg',
      slug: 'blorg',
      syllables: 1,
      definitions: [
        { id: 1, text: 'An AI meaning', origin: 'ai', example_sentence: 'A blorg brightened the room.', votes_count: 0, votes: [], user: { id: 1, name: 'AI', avatar: null } },
        { id: 2, text: 'A human meaning', origin: null, example_sentence: 'A blorg should stay hidden.', votes_count: 0, votes: [], user: { id: 2, name: 'Human', avatar: null } },
        { id: 3, text: 'An older AI meaning', origin: 'ai', example_sentence: null, votes_count: 0, votes: [], user: { id: 1, name: 'AI', avatar: null } },
      ],
    })
    app = mounted.app

    expect(mounted.target.textContent).toContain('A blorg brightened the room.')
    expect(mounted.target.textContent).not.toContain('A blorg should stay hidden.')
  })

  it('renders a backfilled example from a definition.updated payload', async () => {
    const mounted = mountShow({
      id: 42,
      text: 'blorg',
      slug: 'blorg',
      syllables: 1,
      definitions: [
        { id: 7, text: 'An older AI meaning', origin: 'ai', example_sentence: null, votes_count: 0, votes: [], user: { id: 1, name: 'AI', avatar: null } },
      ],
    })
    app = mounted.app

    listeners['word.42.definitions:.definition.updated']({
      definition: { id: 7, example_sentence: 'A blorg brightened the room.' },
    })
    await nextTick()

    expect(mounted.target.textContent).toContain('Example:')
    expect(mounted.target.textContent).toContain('A blorg brightened the room.')
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

describe('definition reporting', () => {
  const word = {
    id: 42,
    text: 'blorg',
    slug: 'blorg',
    syllables: 1,
    definitions: [
      { id: 7, text: 'A live definition', votes_count: 0, votes: [], user: { id: 2, name: 'Author', avatar: null } },
      { id: 8, text: '[deleted]', removed_at: '2026-10-07T00:00:00.000000Z', votes_count: 0, votes: [], user: { id: 3, name: 'Other', avatar: null } },
    ],
  }

  let app

  beforeEach(() => {
    window.Echo = {
      channel: vi.fn(() => ({ listen: vi.fn(), name: 'test' })),
      leaveChannel: vi.fn(),
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
  })

  it('shows one action for each live definition and targets the clicked definition', async () => {
    const mounted = mountShow(word, { id: 1, name: 'Reporter' })
    app = mounted.app
    const { target } = mounted

    const reportButtons = target.querySelectorAll('[aria-label="Report this definition"]')
    expect(reportButtons).toHaveLength(1)
    expect(reportButtons[0].getAttribute('aria-label')).toBe('Report this definition')

    reportButtons[0].click()
    await nextTick()

    const dialog = target.querySelector('[data-testid="definition-report-dialog"]')
    expect(dialog.dataset.targetType).toBe('definition')
    expect(dialog.dataset.target).toBe('7')
    expect(dialog.textContent).toBe('definition_offensive')
  })

  it('does not show definition report actions to guests', () => {
    const mounted = mountShow(word)
    app = mounted.app
    const { target } = mounted

    expect(target.querySelectorAll('[aria-label="Report this definition"]')).toHaveLength(0)
  })
})
