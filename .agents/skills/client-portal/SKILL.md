---
name: client-portal
description: Build the Vue 3, Inertia, TypeScript client portal and Telegram Mini App-compatible UI. Use for portal routes, controllers, pages, components, responsive behavior, or runtime mode detection.
---

# Client Portal

1. Read the affected `REQ-*`, `docs/architecture/client-portal.md`, nearby
   pages/components, and installed package versions.
2. Use the installed Inertia/Vue APIs and Boost version-specific documentation
   for the exact feature. Use Inertia navigation and form primitives rather
   than a parallel request or navigation stack.
3. Keep controllers thin, obtain data through Application actions or query
   objects, and keep business decisions and organization scope server-side.
4. Treat client-provided identifiers, including `organization_id`, as
   untrusted. Preserve the runtime extension point for Telegram Mini App mode.
5. Apply the canonical `.ai/rules/product-ui.md` first-time usability gate.
   A new or substantially changed Portal/Mini App screen must be usable by an
   ordinary person without code, database, or ТЗ knowledge, with clear
   loading, empty, error, success, and next-action states. Test 0/1/many and
   realistic 10–30 object collections where the screen is a collection UI.
6. Use focused server/frontend regression coverage and a browser journey only
   for a valuable changed path. Run lint, typecheck, build, and affected PHP
   checks only when the changed risk or release gate requires them.
7. For a bounded UI fix, verify the exact owner-visible path before broad
   browser or frontend suites. Full Playwright requires an explicit request,
   scheduled gate, or risk-based need.
