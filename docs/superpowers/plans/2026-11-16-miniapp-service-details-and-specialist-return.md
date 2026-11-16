# Mini App service details and specialist return Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let clients read the full service description in the catalog and open the selected specialist's full profile from the booking time screen without losing booking state.

**Architecture:** Keep the existing `ProjectPortalService` projection and CRM-owned fields. Render service cards as semantic articles with native disclosure and an independent booking link. Preserve an allowlisted booking query through the existing specialist profile route and derive the time-screen profile link from reactive client state for the selected slot.

**Tech Stack:** Laravel 13, Inertia, Vue 3, TypeScript, existing portal CSS, PHPUnit feature tests, Playwright Chromium/WebKit against staging.

**Spec:** `docs/superpowers/specs/2026-11-16-miniapp-service-details-and-specialist-return-design.md`

## Global Constraints

- Work only in Draft PR #82; do not touch medical Stage 1B or Denis requirements.
- Use existing `summary`, localized `description_ru`/`description_en`, image, price, duration, service, and specialist projections.
- Do not add fields, tables, catalogs, editors, unsafe HTML, or automatic content changes.
- Keep booking flow, `service_id`, specialist assignment, prices, duration, images, and authorization unchanged.
- Staging is the only product acceptance environment; do not run local application/database/browser infrastructure.
- Verify exact deployed SHA in one Chromium worker and one WebKit worker; keep evidence outside the repository.
- PR #82 remains Draft; do not merge, mark Ready, or deploy production.

## Review Focus

- Equal summary/full description must not render duplicate disclosure text; cover in the service-card E2E test.
- Empty full description must not render a dead disclosure control; cover in the service-card E2E test.
- Newlines, lists, special characters, and long descriptions must remain safe and readable; assert text and CSS behavior in browser coverage.
- Disclosure must not navigate or alter booking URL; assert before and after the independent booking CTA action.
- Returning from specialist profile must retain selected service, specialist, format, date/time, timezone, and booking step when present; assert URL and selected slot.

---

### Task 1: Service card disclosure and independent booking action

**Files:**
- Modify: `resources/js/Components/Portal/ServiceCard.vue`
- Modify: `resources/js/Pages/Services/Index.vue`
- Modify: `resources/css/app.css`
- Modify: `resources/js/locales/portal.ts`
- Test: `tests/Feature/ServiceVerticalSliceTest.php`
- Test: `tests/e2e/portal.spec.ts`

**Interfaces:**
- Consumes: `ProjectPortalService::handle()` fields `summary`, `description`, `imageUrl`, `durationMinutes`, `priceMajor`, and `priceCurrency`.
- Produces: Service cards with `article` roots, optional native disclosure, safe pre-wrapped full description, and an independent booking link carrying `service_id`.

- [ ] **Step 1: Write failing feature assertions** for a published service projection that carries separate `summary` and localized `description`, and for a service with an empty full description that carries `description: null` without changing the summary.
- [ ] **Step 2: Run the focused feature test on the authorized staging/CI path** and confirm it fails only if the projection contract is missing; do not run application tests on the Mac.
- [ ] **Step 3: Extend `ServiceCard.vue`** so the service branch uses an `article`, renders the existing image/name/summary/meta, renders `<details>` only when the full description is non-empty and differs from the summary, interpolates the description as text, and keeps a separate `<a>` booking CTA. Keep non-service purchase behavior intact and apply the same no-duplicate disclosure rule where the projection has a full description.
- [ ] **Step 4: Add localized disclosure labels** for RU and EN in `resources/js/locales/portal.ts`, using the existing `portalText`/`t` mechanism rather than hard-coded locale branching in the component.
- [ ] **Step 5: Adjust only service-card CSS** so the closed card remains compact, the open description has readable width/line-height/spacing and `white-space: pre-wrap`, `align-self: start` prevents an expanded card from forcing content in unrelated grid items, and narrow viewports do not overflow.
- [ ] **Step 6: Add a failing Playwright scenario** covering long text with paragraphs/list-like lines and special characters, empty/equal descriptions, native keyboard toggle, mouse/touch-equivalent click, no URL change on disclosure, independent booking navigation with the expected `service_id`, back navigation, reload, RU/EN copy, and widths 320/360/390/768/1024/1440.
- [ ] **Step 7: Run the focused browser test to verify RED** against the current staging candidate, then implement the minimum component/CSS changes and run the focused tests to verify GREEN.

### Task 2: Specialist profile access from the time-selection screen

**Files:**
- Modify: `app/Http/Controllers/Portal/BookingController.php`
- Modify: `app/Http/Controllers/Portal/SpecialistController.php`
- Modify: `resources/js/Pages/Portal/BookingCreate.vue`
- Modify: `resources/css/app.css`
- Test: `tests/Feature/ServiceVerticalSliceTest.php` or the narrowest existing Portal booking feature test file
- Test: `tests/e2e/portal.spec.ts`

**Interfaces:**
- Consumes: existing `SpecialistChoice.detailsUrl`, `SpecialistShow` back URL, `PortalBookingQueryRequest` allowlisted query fields, and `BookingCreate` reactive selection state.
- Produces: a profile link on the time-selection context whenever `selectedSpecialist` exists, with a return URL that retains current booking state and does not create a booking.

- [ ] **Step 1: Write failing feature assertions** that specialist details URLs include the selected service and specialist and that the specialist profile back URL preserves only the allowlisted booking query state.
- [ ] **Step 2: Run the focused feature test on the authorized staging/CI path** and confirm the expected RED result.
- [ ] **Step 3: Update `BookingController`** to include the selected specialist in each generated details URL and preserve the already validated booking context fields that are safe to carry to the profile.
- [ ] **Step 4: Update `SpecialistController`** to construct its existing booking back link from a strict allowlist of scalar validated query values, keeping organization and assignment validation unchanged and avoiding an arbitrary redirect target.
- [ ] **Step 5: Add a reactive `selectedSpecialistDetailsUrl` in `BookingCreate.vue`** that starts from the existing `detailsUrl` and carries the current service, specialist, format, date range, timezone, location, patient, selected slot, and confirmation step where present. Render the link in the existing time-screen context actions with the existing `specialist.details` translation; do not alter the specialist choice flow or booking submission.
- [ ] **Step 6: Add focused CSS** so the new link wraps with existing context actions on mobile and does not introduce horizontal overflow.
- [ ] **Step 7: Add a failing Playwright scenario** for a fixture with one specialist: open the booking time screen, open the full profile, verify the profile, return, verify service/specialist/time selection and URL state, use back/forward, and confirm no booking was created. Cover the multi-specialist details link as a regression and all required widths.
- [ ] **Step 8: Run the focused browser test to verify RED**, implement the minimum changes, then run the feature and browser tests to verify GREEN.

### Task 3: Exact-SHA staging acceptance and PR handoff

**Files:**
- Modify: PR #82 description only after final code verification; no repository product files unless a test exposes a scoped defect.
- Evidence: screenshots and run logs outside the repository.

- [ ] **Step 1: Push the coherent candidate to the existing PR branch and record its final SHA.**
- [ ] **Step 2: Deploy only through the existing staging workflow if required by the repository process, then verify health and exact deployed SHA; do not use local servers or databases.**
- [ ] **Step 3: Run one sequential Chromium staging journey** for CRM-edited summary/full description to catalog disclosure/collapse, booking CTA, specialist profile from time selection, return state, reload, back/forward, RU/EN, and widths 320/360/390/768/1024/1440.
- [ ] **Step 4: Run the same bounded journey once in WebKit**, with touch-compatible activation and overflow checks.
- [ ] **Step 5: Verify PostgreSQL-backed staging projection and booking route state without destructive writes or extra real-client bookings; classify each requested outcome as PASS/FAIL/NOT RUN from observed evidence.**
- [ ] **Step 6: Update PR #82 description with Final SHA, exact staging SHA, changed behavior, browser/UX status, open REAL BUGs, and direct staging links. Keep the PR Draft and do not merge or mark Ready.
