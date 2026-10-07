import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

let mountedApp

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

function mountShow(word, user = null) {
  inertia.page.props.auth = { user, favourite_ids: [] }
  const target = globalThis.document.createElement('div')
  globalThis.document.body.appendChild(target)
  mountedApp = createApp(Show, { word })
  mountedApp.config.globalProperties.$page = inertia.page
  const naiveUiComponents = ['NAvatar', 'NButton', 'NInput', 'NPopconfirm', 'NSelect', 'NTooltip']
  naiveUiComponents.forEach((component) => {
    mountedApp.component(component, component === 'NButton' ? buttonStub : naiveUiStub)
  })
  mountedApp.mount(target)

  return target
}

describe('word definition realtime updates', () => {
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
    mountedApp?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
  })

  it('renders the category from a received definition.created payload', async () => {
    const target = mountShow({
        id: 42,
        text: 'blorg',
        slug: 'blorg',
        syllables: 1,
        definitions: [],
      })

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

  beforeEach(() => {
    window.Echo = {
      channel: vi.fn(() => ({ listen: vi.fn(), name: 'test' })),
      leaveChannel: vi.fn(),
    }
  })

  afterEach(() => {
    mountedApp?.unmount()
    globalThis.document.body.innerHTML = ''
    delete window.Echo
  })

  it('shows one action for each live definition and targets the clicked definition', async () => {
    const target = mountShow(word, { id: 1, name: 'Reporter' })

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
    const target = mountShow(word)

    expect(target.querySelectorAll('[aria-label="Report this definition"]')).toHaveLength(0)
  })
})
