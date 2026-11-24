import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import {defineConfig, globalIgnores} from 'eslint/config';

export default defineConfig([
  globalIgnores(['dist', 'playwright-report', 'test-results']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite
    ],
    languageOptions: {
      globals: globals.browser
    },
    rules: {
      curly: ['error', 'all'],
      'padding-line-between-statements': [
        'error',
        {blankLine: 'always', prev: '*', next: 'return'},
        {blankLine: 'always', prev: 'if', next: '*'}
      ],
      // Font Awesome est en SVG (composant Icon), pas en feuille de style du CDN : une classe
      // « fas fa-… » n'afficherait rien.
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "JSXAttribute[name.name='className'] Literal[value=/(^|\\s)(fa|fas|far|fab|fa-solid|fa-regular|fa-brands)\\s+fa-/]",
          message: 'Icône Font Awesome en classe : utiliser <Icon icon={faNom} /> (design-system/components/Icon.tsx).'
        },
        {
          selector:
            "JSXAttribute[name.name='className'] TemplateElement[value.raw=/(^|\\s)(fa|fas|far|fab|fa-solid|fa-regular|fa-brands)\\s/]",
          message: 'Icône Font Awesome en classe : utiliser <Icon icon={faNom} /> (design-system/components/Icon.tsx).'
        },
        // La CSP (nginx.conf) interdit les styles en ligne : une mise en forme passe par une classe CSS.
        {
          selector: "JSXAttribute[name.name='style']",
          message: 'Style en ligne interdit par la CSP (style-src sans unsafe-inline) : utiliser une classe CSS.'
        }
      ]
    }
  }
]);
