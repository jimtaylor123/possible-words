import { createApp, h, nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard.vue';

const inertia = vi.hoisted(() => ({ router: { get: vi.fn(), patch: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia.router }));
vi.mock('@/Components/Layout.vue', () => ({ default: { render() { return h('div', this.$slots.default?.()); } } }));
const stub = { props: ['value', 'options'], emits: ['update:value'], render() { return h('select', { ...this.$attrs, value: this.value ?? '', onChange: (e) => this.$emit('update:value', e.target.value) }, this.options?.map((o) => h('option', { value: o.value ?? '' }, o.label))); } };
const slotStub = { render() { return h('div', this.$slots.default?.()); } };

describe('Admin Dashboard', () => {
    let app;
    const mount = (data = []) => {
        vi.stubGlobal('route', vi.fn(() => '/admin'));
        const target = globalThis.document.body.appendChild(globalThis.document.createElement('div'));
        app = createApp(Dashboard, { reports: { data, current_page: 1, last_page: 1 }, filters: { reason: null, status: 'open' }, reasonOptions: [{ label: 'All types', value: null }], statusOptions: [{ label: 'Open', value: 'open' }, { label: 'Dismissed', value: 'dismissed' }, { label: 'Muted', value: 'muted' }, { label: 'All', value: 'all' }] });
        app.config.globalProperties.$page = { props: { flash: {} } };
        app.component('NSelect', stub); app.component('NPagination', stub); app.component('NModal', slotStub); app.component('NButton', slotStub); app.component('NInput', slotStub);
        app.mount(target); return target;
    };
    afterEach(() => { app?.unmount(); globalThis.document.body.innerHTML = ''; inertia.router.get.mockReset(); vi.unstubAllGlobals(); });

    it('renders grouped submissions and dismissed history', () => {
        const target = mount([{ id: 7, reason: 'word_offensive', status: 'open', reporter_count: 2, target: { type: 'word', text: 'florp', url: null }, reports: [{ id: 1, reporter: { name: 'Ada' }, submitted_at: '2026-10-09T12:00:00Z', explanation: 'Context' }], actions: [], prior_dismissed: [{ id: 2, reporter_count: 1 }] }]);
        expect(target.textContent).toContain('Unique reporters: 2');
        expect(target.textContent).toContain('Ada');
        expect(target.textContent).toContain('Prior dismissed cases');
    });

    it('applies the muted filter', async () => {
        const target = mount(); const selects = target.querySelectorAll('select');
        selects[1].value = 'muted'; selects[1].dispatchEvent(new globalThis.Event('change')); await nextTick();
        expect(inertia.router.get).toHaveBeenCalledWith('/admin', { reason: undefined, status: 'muted' }, { preserveState: true, replace: true });
    });
});
