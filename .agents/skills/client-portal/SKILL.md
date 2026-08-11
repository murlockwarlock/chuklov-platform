---
name: client-portal
description: Build the Vue 3, Inertia, TypeScript client portal and Telegram Mini App-compatible UI. Use for portal routes, controllers, pages, components, responsive behavior, or runtime mode detection.
---

# Client Portal

1. Read the affected `REQ-*` and `docs/architecture/client-portal.md`.
2. Keep controllers thin and obtain data through Application actions or query objects.
3. Use strict TypeScript and focused Vue components; do not place business decisions in the browser.
4. Preserve responsive web behavior and the runtime extension point for Telegram Mini App mode.
5. Treat all client-provided identifiers, including `organization_id`, as untrusted.
6. Keep accessibility and explicit loading, empty, error, and success states in the interaction design.
7. Add feature coverage for server behavior and Playwright coverage only for a valuable user path.
8. Run frontend lint, typecheck, build, and affected PHP tests.
