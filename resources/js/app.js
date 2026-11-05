import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { theme, overrides } from './design/theme';
import { NConfigProvider } from 'naive-ui';
import { ZiggyVue } from 'ziggy-js';
import {
    // create naive ui
    create,
    // component
    NAutoComplete,
    NAvatar,
    NButton,
    NButtonGroup,
    NCard,
    NDataTable,
    NDropdown,
    NInput,
    NModal,
    NSelect,
    NPagination,
    NPopconfirm,
    NPopover,
    NTooltip,
} from 'naive-ui';

const naive = create({
    components: [
        NAutoComplete,
        NAvatar,
        NButton,
        NButtonGroup,
        NCard,
        NDataTable,
        NDropdown,
        NInput,
        NModal,
        NSelect,
        NPagination,
        NPopconfirm,
        NPopover,
        NTooltip,
    ]
});

createInertiaApp({
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(NConfigProvider, { theme: theme.value, themeOverrides: overrides.value }, { default: () => h(App, props) }) })
            .use(plugin)
            .use(ZiggyVue)
            .use(naive)
            .mount(el);
    },
});
