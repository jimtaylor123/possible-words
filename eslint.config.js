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
