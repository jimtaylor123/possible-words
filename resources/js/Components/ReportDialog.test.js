import { createApp, h, nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ReportDialog from './ReportDialog.vue';

const inertia = vi.hoisted(() => ({
    page: { props: { flash: {} } },
    router: { post: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: inertia.router,
    usePage: () => inertia.page,
}));

const modalStub = {
    render() {
        return h('div', this.$slots.default?.());
    },
};

const selectStub = {
    props: ['modelValue', 'options'],
    emits: ['update:value'],
    render() {
        return h('select', {
            value: this.modelValue,
            onChange: (event) => this.$emit('update:value', event.target.value),
        }, this.options.map((option) => h('option', { value: option.value }, option.label)));
    },
};

const inputStub = {
    props: ['modelValue'],
    emits: ['update:value'],
    render() {
        return h('textarea', {
            value: this.modelValue,
            onInput: (event) => this.$emit('update:value', event.target.value),
        });
    },
};

const buttonStub = {
    props: ['attrType'],
    emits: ['click'],
    render() {
        return h('button', { type: this.attrType ?? 'button', onClick: () => this.$emit('click') }, this.$slots.default?.());
    },
};

describe('ReportDialog', () => {
    let app;

    function mount() {
        const target = globalThis.document.createElement('div');
        globalThis.document.body.appendChild(target);
        app = createApp(ReportDialog, {
            show: true,
            targetType: 'word',
            target: 'blorg',
            reasonOptions: [{ label: 'Not fresh', value: 'word_unfresh' }],
        });
        app.component('NModal', modalStub);
        app.component('NSelect', selectStub);
        app.component('NInput', inputStub);
        app.component('NButton', buttonStub);
        app.mount(target);

        return target;
    }

    afterEach(() => {
        app?.unmount();
        globalThis.document.body.innerHTML = '';
        inertia.page.props.flash = {};
        inertia.router.post.mockReset();
        vi.unstubAllGlobals();
    });

    it('renders supplied reasons and submits the selected reason and explanation', async () => {
        vi.stubGlobal('route', vi.fn(() => '/reports/word/blorg'));
        const target = mount();
        inertia.router.post.mockImplementation((url, data, options) => {
            options.onStart();
            options.onSuccess();
            options.onFinish();
        });

        expect(target.textContent).toContain('Not fresh');

        const select = target.querySelector('select');
        select.value = 'word_unfresh';
        select.dispatchEvent(new Event('change'));
        const textarea = target.querySelector('textarea');
        textarea.value = 'This has been used before.';
        textarea.dispatchEvent(new Event('input'));
        await nextTick();

        target.querySelector('form').dispatchEvent(new Event('submit'));

        expect(globalThis.route).toHaveBeenCalledWith('reports.store', {
            targetType: 'word',
            target: 'blorg',
        });
        expect(inertia.router.post).toHaveBeenCalledWith('/reports/word/blorg', {
            reason: 'word_unfresh',
            explanation: 'This has been used before.',
        }, expect.any(Object));
    });

    it('displays submission feedback from the server', async () => {
        vi.stubGlobal('route', vi.fn(() => '/reports/word/blorg'));
        const target = mount();
        inertia.router.post.mockImplementation((url, data, options) => {
            options.onError({ report: 'You have already reported this item.' });
        });

        target.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();

        expect(target.querySelector('[role="alert"]').textContent).toBe('You have already reported this item.');
    });
});
