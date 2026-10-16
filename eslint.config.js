// ESLint (CI only, pinned in package.json): catches undefined globals, unused code and other runtime
// bugs in the storefront scripts and the qualification tooling. Formatting is not enforced.
const js = require('@eslint/js');
const globals = require('globals');

module.exports = [
  { ignores: ['node_modules/**', 'vendor/**', 'releases/**', 'playwright-report/**', 'test-results/**', 'parity-report/**', '.lighthouseci/**'] },
  js.configs.recommended,
  {
    files: ['themes/**/assets/*.js'],
    languageOptions: { ecmaVersion: 2020, sourceType: 'script', globals: globals.browser },
  },
  {
    files: ['eslint.config.js', 'playwright.config.js', 'lighthouserc.js', 'tests/e2e/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'commonjs', globals: { ...globals.node, ...globals.browser } },
  },
  {
    files: ['tests/parity/**/*.mjs'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.node, ...globals.browser } },
  },
];
