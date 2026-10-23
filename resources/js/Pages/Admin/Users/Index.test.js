/* eslint-disable vue/one-component-per-file */
import { createApp, h, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const inertia = vi.hoisted(() => ({ router: { get: vi.fn(), patch: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({ router: inertia.router }))
vi.mock('@/Components/Layout.vue', () => ({ default: { render() { return h('div', this.$slots.default?.()) } } }))
const inputStub = { props: ['value'], emits: ['update:value'], render() { return h('textarea', { ...this.$attrs, value: this.value, onInput: (event) => this.$emit('update:value', event.target.value) }) } }
const selectStub = { props: ['value', 'options'], emits: ['update:value'], render() { return h('select', { ...this.$attrs, value: this.value, onChange: (event) => this.$emit('update:value', event.target.value) }, this.options?.map((option) => h('option', { value: option.value }, option.label))) } }
const modalStub = { props: ['show'], render() { return this.show ? h('div', this.$slots.default?.()) : null } }
const buttonStub = { render() { return h('button', this.$attrs, this.$slots.default?.()) } }

describe('Admin user management', () => {
  let app
  const users = { data: [{ id: 2, name: 'Ada Lovelace', email: 'ada@example.com', avatar: null, last_active_at: null, activity_state: 'red', definitions_count: 3, reports_count: 2, word_reports_count: 1, definition_reports_count: 1, suspended_at: null }], current_page: 1, last_page: 2 }
  const mount = (withPager = false, userData = users) => {
    vi.stubGlobal('route', vi.fn((name, id) => id ? `/admin/users/${id}/${name.endsWith('.suspend') ? 'suspend' : 'unsuspend'}` : '/admin/users'))
    const target = globalThis.document.body.appendChild(globalThis.document.createElement('div'))
    app = createApp(Index, { users: userData, filters: { search: '', sort: 'last_active', direction: 'desc' } })
    app.config.globalProperties.$page = { props: { auth: { user: { id: 1 } }, flash: {} } }
    app.config.globalProperties.route = globalThis.route
    app.component('NInput', inputStub)
    app.component('NSelect', selectStub)
    app.component('NAvatar', { render() { return h('div', this.$slots.default?.()) } })
    app.component('NPagination', withPager ? { emits: ['update:page'], render() { return h('button', { onClick: () => this.$emit('update:page', 2) }, 'Page 2') } } : { render() { return h('div') } })
    app.component('NModal', modalStub)
    app.component('NButton', buttonStub)
    app.mount(target)
    return target
  }
  afterEach(() => { app?.unmount(); globalThis.document.body.innerHTML = ''; inertia.router.get.mockReset(); inertia.router.patch.mockReset(); vi.unstubAllGlobals() })
  const clickButton = (target, label) => Array.from(target.querySelectorAll('button')).find((button) => button.textContent === label).click()

  it('renders accessible unknown activity and report details', async () => {
    const target = mount()
    expect(target.textContent).toContain('Inactive or unknown activity')
    expect(target.textContent).toContain('Last active: Unknown')
    clickButton(target, 'View details'); await nextTick()
    expect(target.textContent).toContain('Word reports: 1')
  })

  it('preserves filters when searching, sorting, and changing pages', async () => {
    const target = mount(true)
    const [searchInput, sortSelect, directionSelect] = target.querySelectorAll('textarea, select')
    searchInput.value = 'Ada'; searchInput.dispatchEvent(new globalThis.Event('input')); await nextTick()
    sortSelect.value = 'reports'; sortSelect.dispatchEvent(new globalThis.Event('change')); await nextTick()
    directionSelect.value = 'asc'; directionSelect.dispatchEvent(new globalThis.Event('change')); await nextTick()
    target.querySelector('form').dispatchEvent(new globalThis.Event('submit')); await nextTick()

    expect(inertia.router.get).toHaveBeenCalledWith('/admin/users', { search: 'Ada', sort: 'reports', direction: 'asc' }, { preserveState: true, replace: true })
    clickButton(target, 'Page 2'); await nextTick()
    expect(inertia.router.get).toHaveBeenLastCalledWith('/admin/users', { search: 'Ada', sort: 'reports', direction: 'asc', page: 2 }, { preserveState: true })
  })

  it('submits a trimmed reason and keeps the modal open for server errors', async () => {
    const target = mount()
    clickButton(target, 'Suspend account'); await nextTick()
    expect(target.textContent).toContain('Suspend Ada Lovelace')
    const forms = target.querySelectorAll('form'); forms[forms.length - 1].dispatchEvent(new globalThis.Event('submit')); await nextTick()
    expect(target.textContent).toContain('A suspension reason is required.')

    const reason = target.querySelector('[aria-label="Suspension reason"]')
    reason.value = '  Repeated abuse  '; reason.dispatchEvent(new globalThis.Event('input')); await nextTick()
    forms[forms.length - 1].dispatchEvent(new globalThis.Event('submit')); await nextTick()
    const [, payload, options] = inertia.router.patch.mock.calls[0]
    expect(payload).toEqual({ reason: 'Repeated abuse' })
    options.onError({ user: 'This account is already suspended.' }); await nextTick()
    expect(target.textContent).toContain('This account is already suspended.')
    expect(target.textContent).toContain('Suspend Ada Lovelace')
    options.onSuccess(); await nextTick()
    expect(target.textContent).not.toContain('Suspend Ada Lovelace')
  })

  it('sends no payload when restoring a suspended account', async () => {
    const target = mount(false, { ...users, data: [{ ...users.data[0], suspended_at: '2026-10-23T12:00:00Z' }] })
    clickButton(target, 'Restore account'); await nextTick()
    const form = target.querySelectorAll('form')[1]
    form.dispatchEvent(new globalThis.Event('submit')); await nextTick()

    expect(inertia.router.patch).toHaveBeenCalledWith('/admin/users/2/unsuspend', {}, expect.objectContaining({ preserveScroll: true }))
  })
})
