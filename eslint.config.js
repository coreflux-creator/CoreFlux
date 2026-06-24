// CoreFlux — root ESLint config (Feb-2026).
//
// Lives at /app/eslint.config.js so it can lint BOTH:
//   • dashboard/src/**/*.{js,jsx}
//   • modules/**/ui|components/**/*.{js,jsx}
//
// Focused exclusively on the bug class that has bitten production:
// hooks called after an early `return` (React error #310, shipped in
// MembershipDriftBanner Feb-2026). The guardrail is wired into
// `yarn build` via a prebuild script — any future violation fails
// the build before the bundle reaches spa-assets/.

import reactHooks from './dashboard/node_modules/eslint-plugin-react-hooks/index.js';
import globals    from './dashboard/node_modules/globals/index.js';

export default [
  {
    ignores: [
      '**/node_modules/**',
      '**/dist/**',
      '**/build/**',
      '**/.vite/**',
      'spa-assets/**',
      'dashboard/dist/**',
    ],
  },
  {
    files: ['dashboard/src/**/*.{js,jsx}', 'modules/**/ui/**/*.{js,jsx}'],
    plugins: { 'react-hooks': reactHooks },
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      parserOptions: { ecmaFeatures: { jsx: true } },
      globals: { ...globals.browser, ...globals.node },
    },
    rules: {
      // The actual bug class we care about.
      'react-hooks/rules-of-hooks': 'error',
      // Best-effort — warning only so deliberate stale-closure code
      // doesn't break the build.
      'react-hooks/exhaustive-deps': 'warn',
    },
  },
];
