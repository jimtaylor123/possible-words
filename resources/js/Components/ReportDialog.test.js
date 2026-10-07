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

    function mount({
        targetType = 'word',
        targetValue = 'blorg',
        reasonOptions = [
            { label: 'This word is not fresh', value: 'word_unfresh' },
            { label: 'This word is offensive or obscene', value: 'word_offensive' },
        ],
    } = {}) {
        const success = vi.fn();
        const target = globalThis.document.createElement('div');
        globalThis.document.body.appendChild(target);
        app = createApp(ReportDialog, {
            show: true,
            targetType,
            target: targetValue,
            reasonOptions,
            onSuccess: success,
        });
        app.component('NModal', modalStub);
        app.component('NSelect', selectStub);
        app.component('NInput', inputStub);
        app.component('NButton', buttonStub);
        app.mount(target);

        return { target, success };
    }

    afterEach(() => {
        app?.unmount();
        globalThis.document.body.innerHTML = '';
        inertia.page.props.flash = {};
        inertia.router.post.mockReset();
        vi.unstubAllGlobals();
    });

    it('renders both word reasons and submits the offensive reason and explanation', async () => {
        vi.stubGlobal('route', vi.fn(() => '/reports/word/blorg'));
        const { target, success } = mount();
        inertia.router.post.mockImplementation((url, data, options) => {
            options.onStart();
            options.onSuccess({ props: { flash: { success: 'Report submitted.' } } });
            options.onFinish();
        });

        expect(target.textContent).toContain('This word is not fresh');
        expect(target.textContent).toContain('This word is offensive or obscene');

        const select = target.querySelector('select');
        select.value = 'word_offensive';
        select.dispatchEvent(new globalThis.Event('change'));
        const textarea = target.querySelector('textarea');
        textarea.value = 'This has been used before.';
        textarea.dispatchEvent(new globalThis.Event('input'));
        await nextTick();

        target.querySelector('form').dispatchEvent(new globalThis.Event('submit'));

        expect(globalThis.route).toHaveBeenCalledWith('reports.store', {
            targetType: 'word',
            target: 'blorg',
        });
        expect(inertia.router.post).toHaveBeenCalledWith('/reports/word/blorg', {
            reason: 'word_offensive',
            explanation: 'This has been used before.',
        }, expect.any(Object));
        expect(success).toHaveBeenCalledOnce();
    });

    it('displays duplicate redirect feedback without emitting success', async () => {
        vi.stubGlobal('route', vi.fn(() => '/reports/word/blorg'));
        const { target, success } = mount();
        inertia.router.post.mockImplementation((url, data, options) => {
            options.onStart();
            options.onSuccess({ props: { flash: { error: 'You have already reported this item.' } } });
            options.onFinish();
        });

        target.querySelector('form').dispatchEvent(new globalThis.Event('submit'));
        await nextTick();

        expect(target.querySelector('[role="alert"]').textContent).toBe('You have already reported this item.');
        expect(success).not.toHaveBeenCalled();
    });

    it('submits a definition report with the sole reason and a blank explanation', async () => {
        vi.stubGlobal('route', vi.fn(() => '/reports/definition/42'));
        const { target, success } = mount({
            targetType: 'definition',
            targetValue: 42,
            reasonOptions: [
                { label: 'This definition is offensive or obscene', value: 'definition_offensive' },
            ],
        });
        inertia.router.post.mockImplementation((url, data, options) => {
            options.onStart();
            options.onSuccess({ props: { flash: { success: 'Report submitted.' } } });
            options.onFinish();
        });

        expect(target.textContent).toContain('This definition is offensive or obscene');
        expect(target.textContent).not.toContain('This word is not fresh');

        const select = target.querySelector('select');
        select.value = 'definition_offensive';
        select.dispatchEvent(new globalThis.Event('change'));
        await nextTick();
        target.querySelector('form').dispatchEvent(new globalThis.Event('submit'));

        expect(globalThis.route).toHaveBeenCalledWith('reports.store', {
            targetType: 'definition',
            target: 42,
        });
        expect(inertia.router.post).toHaveBeenCalledWith('/reports/definition/42', {
            reason: 'definition_offensive',
            explanation: '',
        }, expect.any(Object));
        expect(success).toHaveBeenCalledOnce();
    });
});
