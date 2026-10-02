import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';

export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        languageOptions: {
            globals: {
                route: 'readonly',
                window: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                IntersectionObserver: 'readonly',
                AbortController: 'readonly',
                fetch: 'readonly',
            },
        },
    },
    {
        // Node tooling scripts, not browser code.
        files: ['scripts/**/*.mjs'],
        languageOptions: {
            globals: {
                console: 'readonly',
                process: 'readonly',
            },
        },
    },
    {
        // E2E specs run in Node, but callbacks handed to page.evaluate() and
        // page.addInitScript() are serialised and executed in the browser.
        files: ['tests/e2e/**/*.js'],
        languageOptions: {
            globals: {
                document: 'readonly',
                MutationObserver: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
            },
        },
    },
    {
        ignores: [
            'public/build/**',
            'vendor/**',
            'node_modules/**',
            'bootstrap/ssr/**',
            'storage/**',
        ],
    },
    {
        files: ['**/*.vue', '**/*.js'],
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/max-attributes-per-line': ['warn', {
                singleline: { max: 5 },
                multiline: { max: 1 },
            }],
            'vue/component-name-in-template-casing': ['error', 'PascalCase'],
        },
    },
];
