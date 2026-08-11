import eslint from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import vue from 'eslint-plugin-vue';

export default tseslint.config(
    { ignores: ['node_modules', 'public/build', 'public/js/filament'] },
    eslint.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['**/*.{ts,vue}'],
        languageOptions: {
            globals: globals.browser,
            parserOptions: { parser: tseslint.parser },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
);
