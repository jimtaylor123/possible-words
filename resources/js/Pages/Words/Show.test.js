import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const inertia = vi.hoisted(() => ({
  router: { get: vi.fn(), post: vi.fn() },
  page: {
    props: {
      auth: { user: null, favourite_ids: [] },
      flash: {},
    },
  },
}))

vi.mock('@inertiajs/vue3', () => ({
  router: inertia.router,
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

const modalStub = {
  props: ['show'],
  emits: ['update:show'],
  render() {
    if (!this.show) return null

    return h('div', {
      role: 'dialog',
      onClick: (event) => {
        if (event.target === event.currentTarget) this.$emit('update:show', false)
      },
    }, this.$slots.default?.())
  },
}

const buttonStub = {
  render() {
    return h('button', this.$attrs, this.$slots.default?.())
  },
}

const naiveUiComponents = ['n-avatar', 'n-button', 'n-input', 'n-modal', 'n-popconfirm', 'n-select', 'n-tooltip']

// The file mounts Show through a single helper so the component is defined
// only once (vue/one-component-per-file).
function mountShow(word, user = null) {
  inertia.page.props.auth = { user, favourite_ids: [] }
  const target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)

  const app = createApp(Show, { word })
  app.config.globalProperties.$page = inertia.page
  naiveUiComponents.forEach((component) => {
    app.component(component, component === 'n-button' ? buttonStub : component === 'n-modal' ? modalStub : naiveUiStub)
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

describe('definition add action', () => {
  let app

  beforeEach(() => {
    window.Echo = {
      channel: vi.fn(() => ({ name: 'word', listen: vi.fn() })),
      leaveChannel: vi.fn(),
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
  })

  it('keeps the add action beside the heading when there are no definitions', () => {
    const mounted = mountShow({
      id: 42,
      text: 'blorg',
      slug: 'blorg',
      syllables: 1,
      definitions: [],
    }, { id: 1, name: 'Author' })
    app = mounted.app

    const heading = Array.from(mounted.target.querySelectorAll('h2')).find((element) => element.textContent === 'Definitions')

    expect(heading.parentElement.textContent).toContain('Add a definition')
    expect(mounted.target.querySelector('.text-center.py-8').textContent).not.toContain('Add a definition')
  })
})

describe('domain availability chip', () => {
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

  const domainLink = (target) => target.querySelector('a[href*="blorg.com"]')

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

  it.each([
    ['available', 'available', 'buy blorg.com'],
    ['taken', 'taken', 'blorg.com already taken'],
    ['unchecked', 'unchecked', 'visit blorg.com'],
    ['check failed', 'check_failed', 'visit blorg.com'],
    ['absent verdict', undefined, 'visit blorg.com'],
  ])('renders a linked %s domain chip', (_label, domainStatus, label) => {
    const target = mountWithDomain(domainStatus)
    const link = domainLink(target)

    expect(link).not.toBeNull()
    expect(link.getAttribute('href')).toBe('https://blorg.com')
    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
    expect(link.textContent.trim()).toBe(label)
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

  it('shows the word report action beside the pronunciation controls', async () => {
    const mounted = mountShow(word, { id: 1, name: 'Reporter' })
    app = mounted.app
    const { target } = mounted

    const reportButton = target.querySelector('[aria-label="Report this word"]')
    expect(reportButton).not.toBeNull()
    expect(reportButton.closest('.flex.items-center.justify-center.gap-3.mt-3')).not.toBeNull()

    reportButton.click()
    await nextTick()

    const dialog = target.querySelector('[data-testid="definition-report-dialog"]')
    expect(dialog.dataset.targetType).toBe('word')
    expect(dialog.dataset.target).toBe('blorg')
    expect(dialog.textContent).toBe('word_unfresh,word_offensive')
  })

  it('does not show definition report actions to guests', () => {
    const mounted = mountShow(word)
    app = mounted.app
    const { target } = mounted

    expect(target.querySelectorAll('[aria-label="Report this definition"]')).toHaveLength(0)
  })
})

describe('definition modal and shortcuts', () => {
  let app

  const word = {
    id: 42,
    text: 'blorg',
    slug: 'blorg',
    syllables: 1,
    definitions: [],
  }

  beforeEach(() => {
    globalThis.route = vi.fn((name) => `/${name}`)
    window.Echo = {
      channel: vi.fn(() => ({ listen: vi.fn(), name: 'test' })),
      leaveChannel: vi.fn(),
    }
  })

  afterEach(() => {
    app?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
    delete globalThis.route
    vi.clearAllMocks()
  })

  it('opens the centered empty-state modal with the plus shortcut for signed-in users', async () => {
    const mounted = mountShow(word, { id: 1, name: 'Author' })
    app = mounted.app

    expect(mounted.target.textContent).toContain('Add a definition')
    expect(mounted.target.textContent).not.toContain('Submit Definition')

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: '+' }))
    await nextTick()

    expect(mounted.target.textContent).toContain('Submit Definition')
  })

  it('puts the add trigger beside the heading when definitions are listed', () => {
    const mounted = mountShow({
      ...word,
      definitions: [{ id: 1, text: 'A definition', votes_count: 0, votes: [], user: { id: 2, name: 'Author', avatar: null } }],
    }, { id: 1, name: 'Author' })
    app = mounted.app

    const heading = Array.from(mounted.target.querySelectorAll('h2')).find((element) => element.textContent === 'Definitions')
    expect(heading.parentElement.textContent).toContain('Add a definition')
  })

  it('does not activate shortcuts while focus is in an editable control', () => {
    const mounted = mountShow(word, { id: 1, name: 'Author' })
    app = mounted.app
    const input = globalThis.document.createElement('input')
    mounted.target.appendChild(input)

    input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l', bubbles: true }))

    expect(inertia.router.post).not.toHaveBeenCalled()
  })

  it('plays audio with p, toggles favourites with l, and opens the definition modal with +', async () => {
    const play = vi.spyOn(window.HTMLMediaElement.prototype, 'play').mockResolvedValue()
    const mounted = mountShow({ ...word, audio_url: 'https://example.com/blorg.mp3' }, { id: 1, name: 'Author' })
    app = mounted.app

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'p' }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l' }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: '+' }))
    await nextTick()

    expect(play).toHaveBeenCalledOnce()
    expect(inertia.router.post).toHaveBeenCalledWith('/words.favourite', {}, expect.objectContaining({
      preserveScroll: true,
      preserveState: true,
    }))
    expect(mounted.target.getAttribute('role')).not.toBe('dialog')
    expect(mounted.target.querySelector('[role="dialog"]')).not.toBeNull()
  })

  it('does not activate shortcuts for guests, missing audio, modifiers, or repeated keys', () => {
    const mounted = mountShow(word)
    app = mounted.app

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'p' }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l' }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: '+', ctrlKey: true }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l', altKey: true }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l', metaKey: true }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l', shiftKey: true }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l', repeat: true }))

    expect(inertia.router.post).not.toHaveBeenCalled()
    expect(mounted.target.querySelector('[role="dialog"]')).toBeNull()
  })

  it('does not activate shortcuts while the definition or report dialog is open', async () => {
    const play = vi.spyOn(window.HTMLMediaElement.prototype, 'play').mockResolvedValue()
    const mounted = mountShow({ ...word, audio_url: 'https://example.com/blorg.mp3' }, { id: 1, name: 'Author' })
    app = mounted.app

    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: '+' }))
    await nextTick()
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'p' }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l' }))

    expect(play).not.toHaveBeenCalled()
    expect(inertia.router.post).not.toHaveBeenCalled()

    Array.from(mounted.target.querySelectorAll('button')).find((button) => button.textContent === 'Cancel').click()
    await nextTick()
    mounted.target.querySelector('[aria-label="Report this word"]').click()
    await nextTick()
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'p' }))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l' }))

    expect(play).not.toHaveBeenCalled()
    expect(inertia.router.post).not.toHaveBeenCalled()
  })

  it('shows the contribution sign-in prompt only to guests', () => {
    const guest = mountShow(word)
    app = guest.app

    expect(guest.target.textContent).toContain('Want to add a definition or vote?')
    guest.app.unmount()

    const signedIn = mountShow(word, { id: 1, name: 'Author' })
    app = signedIn.app

    expect(signedIn.target.textContent).not.toContain('Want to add a definition or vote?')
  })

  it('removes the shortcut listener when the page unmounts', () => {
    const removeEventListener = vi.spyOn(window, 'removeEventListener')
    const mounted = mountShow(word, { id: 1, name: 'Author' })
    app = mounted.app

    mounted.app.unmount()
    app = null

    expect(removeEventListener).toHaveBeenCalledWith('keydown', expect.any(Function))
    window.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'l' }))
    expect(inertia.router.post).not.toHaveBeenCalled()
  })
})
