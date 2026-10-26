import { createApp, h, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import UserDirectory from './UserDirectory.vue'

const inertia = vi.hoisted(() => ({ router: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({ router: inertia.router, usePage: () => ({ props: { auth: { user: { id: 1 } } } }) }))
const inputStub = { props: ['value'], emits: ['update:value'], render() { return h('textarea', { ...this.$attrs, value: this.value, onInput: (event) => this.$emit('update:value', event.target.value) }) } }
const modalStub = { props: ['show'], render() { return this.show ? h('div', { role: 'dialog' }, this.$slots.default?.()) : null } }
const buttonStub = { render() { return h('button', this.$attrs, this.$slots.default?.()) } }
const dataTableStub = {
  props: ['columns', 'data', 'pagination'],
  emits: ['update:sorter', 'update:page'],
  render() {
    return h('div', { role: 'table', 'aria-label': this.$attrs['aria-label'] }, [
      h('div', { role: 'row' }, this.columns.map((column) => h('button', { type: 'button', 'aria-label': `Sort ${column.title}`, disabled: !column.sorter, onClick: () => column.sorter && this.$emit('update:sorter', { columnKey: column.key, order: column.sortOrder === 'ascend' ? 'descend' : 'ascend' }) }, column.title))),
      ...this.data.map((user) => h('div', { role: 'row' }, this.columns.map((column) => h('div', { role: 'cell' }, column.render ? column.render(user) : user[column.key])))),
      h('button', { type: 'button', onClick: () => this.$emit('update:page', 2) }, 'Page 2'),
    ])
  },
}

describe('Admin user directory', () => {
  let app
  const users = { data: [{ id: 2, name: 'Ada Lovelace', email: 'ada@example.com', avatar: null, last_active_at: null, activity_state: 'red', definitions_count: 3, reports_count: 2, word_reports_count: 1, definition_reports_count: 1, suspended_at: null }], current_page: 1, last_page: 2, total: 21, per_page: 20 }
  const mount = (userData = users) => {
    vi.stubGlobal('route', vi.fn((name, id) => id ? `/admin/users/${id}/${name.endsWith('.suspend') ? 'suspend' : 'unsuspend'}` : '/admin'))
    const target = globalThis.document.body.appendChild(globalThis.document.createElement('div'))
    app = createApp(UserDirectory, { users: userData, filters: { search: '', sort: 'last_active', direction: 'desc' } })
    app.component('NInput', inputStub); app.component('NDataTable', dataTableStub); app.component('NModal', modalStub); app.component('NButton', buttonStub)
    app.mount(target); return target
  }
  afterEach(() => { app?.unmount(); globalThis.document.body.innerHTML = ''; inertia.router.get.mockReset(); inertia.router.patch.mockReset(); vi.useRealTimers(); vi.unstubAllGlobals() })
  const clickButton = (target, label) => Array.from(target.querySelectorAll('button')).find((button) => button.textContent.trim() === label).click()

  it('renders directory columns, totals, activity, and an accessible suspension action', () => {
    const target = mount()
    expect(target.querySelectorAll('[role="row"]')).toHaveLength(2)
    expect(target.textContent).toContain('Inactive')
    expect(target.textContent).toContain('3')
    expect(target.textContent).toContain('2')
    expect(target.textContent).toContain('1 word · 1 definition')
    expect(target.querySelector('button[aria-label="Suspend Ada Lovelace\'s account"]')).not.toBeNull()
  })

  it('renders supplied avatars without a referrer and falls back to initials after an image error', async () => {
    const target = mount({ ...users, data: [{ ...users.data[0], avatar: 'https://lh3.googleusercontent.com/avatar' }] })
    const avatar = target.querySelector('img[alt="Ada Lovelace\'s avatar"]')
    expect(avatar?.getAttribute('src')).toBe('https://lh3.googleusercontent.com/avatar')
    expect(avatar?.getAttribute('referrerpolicy')).toBe('no-referrer')
    avatar.dispatchEvent(new globalThis.Event('error')); await nextTick()
    expect(target.textContent).toContain('AL')
  })

  it('opens an email address in the default mail client', () => {
    const target = mount()
    const email = target.querySelector('a[href="mailto:ada@example.com"]')
    expect(email?.textContent).toBe('ada@example.com')
    expect(email?.getAttribute('title')).toBe('ada@example.com')
  })

  it('debounces searches and sends remote sorter and pager requests for the users tab', async () => {
    vi.useFakeTimers()
    const target = mount()
    const searchInput = target.querySelector('textarea')
    searchInput.value = 'Ada'; searchInput.dispatchEvent(new globalThis.Event('input')); await nextTick()
    expect(inertia.router.get).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(300)
    expect(inertia.router.get).toHaveBeenCalledWith('/admin', { tab: 'users', search: 'Ada', sort: 'last_active', direction: 'desc' }, { preserveState: true, replace: true })
    target.querySelector('button[aria-label="Sort Reports"]').click(); await nextTick()
    expect(inertia.router.get).toHaveBeenLastCalledWith('/admin', { tab: 'users', search: 'Ada', sort: 'reports', direction: 'asc' }, { preserveState: true, replace: true })
    target.querySelector('button[aria-label="Sort Reports"]').click(); await nextTick()
    expect(inertia.router.get).toHaveBeenLastCalledWith('/admin', { tab: 'users', search: 'Ada', sort: 'reports', direction: 'desc' }, { preserveState: true, replace: true })
    clickButton(target, 'Page 2'); await nextTick()
    expect(inertia.router.get).toHaveBeenLastCalledWith('/admin', { tab: 'users', search: 'Ada', sort: 'reports', direction: 'desc', page: 2 }, { preserveState: true })
  })

  it('submits a trimmed suspension reason and restores suspended accounts', async () => {
    const target = mount()
    target.querySelector('button[aria-label="Suspend Ada Lovelace\'s account"]').click(); await nextTick()
    const forms = target.querySelectorAll('form'); forms[0].dispatchEvent(new globalThis.Event('submit')); await nextTick()
    expect(target.textContent).toContain('A suspension reason is required.')
    const reason = target.querySelector('[aria-label="Suspension reason"]'); reason.value = '  Repeated abuse  '; reason.dispatchEvent(new globalThis.Event('input')); await nextTick()
    forms[0].dispatchEvent(new globalThis.Event('submit')); await nextTick()
    expect(inertia.router.patch.mock.calls[0][1]).toEqual({ reason: 'Repeated abuse' })

    app.unmount(); globalThis.document.body.innerHTML = ''
    const suspended = mount({ ...users, data: [{ ...users.data[0], suspended_at: '2026-10-23T12:00:00Z' }] })
    clickButton(suspended, 'Restore'); await nextTick()
    suspended.querySelectorAll('form')[0].dispatchEvent(new globalThis.Event('submit')); await nextTick()
    expect(inertia.router.patch).toHaveBeenLastCalledWith('/admin/users/2/unsuspend', {}, expect.objectContaining({ preserveScroll: true }))
  })
})
