---
name: inertia-vue-development
description: "Use for Inertia v3 Vue client work: pages, layouts, forms, navigation, `Link`, `Form`, `useForm`, `useHttp`, router visits, deferred/merged props, prefetching, optimistic updates, polling, infinite scroll, and other Inertia protocol behavior."
---

# Inertia Vue Development

1. Read the affected REQ, `docs/architecture/client-portal.md`, existing page/components, and package versions in `package.json`.
2. Use Boost `search-docs` for the installed Inertia Laravel and Vue versions before applying client or server APIs. Query the exact feature and testing behavior; do not rely on remembered v1/v2 syntax.
3. Keep Vue pages under the configured `resources/js/Pages` path, use a single root element, and follow nearby TypeScript/component conventions.
4. Use Inertia navigation and form primitives rather than introducing a parallel request/navigation stack. Keep server authorization and organization scope authoritative.
5. Provide explicit loading/empty/error states. Deferred or visibility-loaded props require a skeleton or equivalent stable fallback.
6. Keep Telegram Mini App detection in the runtime adapter; desktop, mobile web, and Mini App share the same Application/domain behavior.
7. Verify changed behavior with focused frontend/feature coverage, then run ESLint, `vue-tsc`, Vite build, and Playwright when user-visible behavior changes.

Useful Boost query groups include `form component validation reset`, `useForm`, `useHttp`, `navigation link prefetch`, `deferred props skeleton`, `optimistic updates`, `polling`, `infinite scroll`, `layout props`, and `testing Inertia response`.
