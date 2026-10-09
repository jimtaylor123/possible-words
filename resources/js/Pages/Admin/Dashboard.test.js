import { createApp, h, nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard.vue';

const inertia = vi.hoisted(() => ({
    router: { get: vi.fn(), patch: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({ router: inertia.router }));
vi.mock('@/Components/Layout.vue', () => ({
    default: { render() { return h('div', this.$slots.default?.()); } },
}));

const selectStub = {
    props: ['value', 'options'],
    emits: ['update:value'],
    render() {
        return h('select', {
            ...this.$attrs,
            value: this.value ?? '',
            onChange: (event) => this.$emit('update:value', event.target.value),
        }, this.options.map((option) => h('option', { value: option.value ?? '' }, option.label)));
    },
};

const paginationStub = {
    props: ['page', 'pageCount'],
    emits: ['update:page'],
    render() {
        return h('select', {
            'aria-label': 'Pagination',
            value: this.page,
            onChange: (event) => this.$emit('update:page', Number(event.target.value)),
        }, Array.from({ length: this.pageCount }, (_, index) => h('option', { value: index + 1 }, index + 1)));
    },
};

const componentStub = { render() { return h('div', this.$slots.default?.()); } };

describe('Admin Dashboard', () => {
    let app;

    function mount({ flash = {}, reports, reasonOptions, statusOptions } = {}) {
        vi.stubGlobal('route', vi.fn(() => '/admin'));
        const target = globalThis.document.createElement('div');
        globalThis.document.body.appendChild(target);
        app = createApp(Dashboard, {
            reports: reports ?? { data: [], current_page: 2, last_page: 2 },
            filters: { reason: null, status: 'open' },
            reasonOptions: reasonOptions ?? [
                { label: 'All types', value: null },
                { label: 'Offensive word', value: 'word_offensive' },
            ],
            statusOptions: statusOptions ?? [
                { label: 'Open', value: 'open' },
                { label: 'Reviewed', value: 'reviewed' },
                { label: 'Dismissed', value: 'dismissed' },
            ],
        });
        app.config.globalProperties.$page = { props: { flash } };
        app.component('NSelect', selectStub);
        app.component('NPagination', paginationStub);
        app.component('NModal', componentStub);
        app.component('NButton', componentStub);
        app.component('NInput', componentStub);
        app.mount(target);

        return target;
    }

    afterEach(() => {
        app?.unmount();
        globalThis.document.body.innerHTML = '';
        inertia.router.get.mockReset();
        vi.unstubAllGlobals();
    });

    it('renders the duplicate open report conflict from an error flash', () => {
        const target = mount({
            flash: { error: 'This report cannot be reopened because the reporter already has an open report for this item.' },
        });

        expect(target.querySelector('[role="alert"]').textContent)
            .toBe('This report cannot be reopened because the reporter already has an open report for this item.');
    });

    it('resets pagination before applying filters from a later page', async () => {
        const target = mount();
        const selects = target.querySelectorAll('select');

        selects[0].value = 'word_offensive';
        selects[0].dispatchEvent(new globalThis.Event('change'));
        await nextTick();

        expect(inertia.router.get).toHaveBeenCalledWith('/admin', {
            reason: 'word_offensive',
            status: 'open',
        }, { preserveState: true, replace: true });
        expect(target.querySelector('[aria-label="Pagination"]').value).toBe('1');
    });

    it('renders a success flash banner', () => {
        const target = mount({ flash: { success: 'Report reviewed.' } });

        expect(target.querySelector('[role="status"]').textContent).toBe('Report reviewed.');
    });

    it('renders every configured reason and status filter option with its value', () => {
        const target = mount({
            reasonOptions: [
                { label: 'All types', value: null },
                { label: 'Unfresh word', value: 'word_unfresh' },
                { label: 'Offensive word', value: 'word_offensive' },
                { label: 'Offensive definition', value: 'definition_offensive' },
            ],
            statusOptions: [
                { label: 'Open', value: 'open' },
                { label: 'Reviewed', value: 'reviewed' },
                { label: 'Dismissed', value: 'dismissed' },
            ],
        });
        const [reasonSelect, statusSelect] = target.querySelectorAll('select');
        const options = (select) => Array.from(select.querySelectorAll('option'))
            .map((option) => [option.value, option.textContent]);

        expect(options(reasonSelect)).toEqual([
            ['', 'All types'],
            ['word_unfresh', 'Unfresh word'],
            ['word_offensive', 'Offensive word'],
            ['definition_offensive', 'Offensive definition'],
        ]);
        expect(options(statusSelect)).toEqual([
            ['open', 'Open'],
            ['reviewed', 'Reviewed'],
            ['dismissed', 'Dismissed'],
        ]);
    });

    it('applies a lifecycle status filter and resets pagination from a later page', async () => {
        const target = mount();
        const selects = target.querySelectorAll('select');

        selects[1].value = 'reviewed';
        selects[1].dispatchEvent(new globalThis.Event('change'));
        await nextTick();

        expect(inertia.router.get).toHaveBeenCalledWith('/admin', {
            reason: undefined,
            status: 'reviewed',
        }, { preserveState: true, replace: true });
        expect(target.querySelector('[aria-label="Pagination"]').value).toBe('1');
    });

    it('renders each review action with its admin, timestamp, and note', () => {
        const target = mount({
            reports: {
                data: [{
                    id: 7,
                    reason: 'word_offensive',
                    status: 'reviewed',
                    explanation: null,
                    submitted_at: '2026-10-09T12:00:00.000Z',
                    reporter: { id: 1, name: 'Reporter' },
                    target: { type: 'word', text: 'florp', word_text: null, url: null },
                    latest_review: null,
                    actions: [
                        { id: 10, action: 'reviewed', note: 'Looked at it.', created_at: '2026-10-09T12:30:00.000Z', admin: { id: 2, name: 'Admin Ada' } },
                        { id: 11, action: 'reopened', note: 'Needs another look.', created_at: '2026-10-09T13:00:00.000Z', admin: { id: 3, name: 'Admin Bob' } },
                    ],
                }],
                current_page: 1,
                last_page: 1,
            },
        });
        const history = target.querySelector('[aria-label="Review history for report 7"]');

        expect(history).not.toBeNull();
        const items = history.querySelectorAll('li');
        expect(items).toHaveLength(2);
        expect(items[0].textContent).toContain('reviewed');
        expect(items[0].textContent).toContain('Admin Ada');
        expect(items[0].textContent).toContain('Looked at it.');
        expect(items[0].textContent).toContain('2026');
        expect(items[1].textContent).toContain('reopened');
        expect(items[1].textContent).toContain('Admin Bob');
        expect(items[1].textContent).toContain('Needs another look.');
    });
});
