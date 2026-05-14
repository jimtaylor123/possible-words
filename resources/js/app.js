import './bootstrap';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import {
    // create naive ui
    create,
    // component
    NAvatar,
    NButton,
    NCard,
    NDropdown,
    NInput,
    NSelect,
    NPagination,
} from 'naive-ui';

const naive = create({
    components: [
        NAvatar,
        NButton,
        NCard,
        NDropdown,
        NInput,
        NSelect,
        NPagination,
    ]
});

createInertiaApp({
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(naive)
            .mount(el);
    },
});
