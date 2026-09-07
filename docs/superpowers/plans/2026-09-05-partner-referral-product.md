# Partner Referral Product Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the Phase 1 partner/referral product around the existing referral identity, first-touch attribution, Finance evidence, reward ledger, Portal, and Telegram Mini App authorities.

**Architecture:** Add an organization-scoped partner enrollment, campaign-link, and append-only link-visit evidence layer subordinate to `ClientReferralIdentity` and `ReferralRelationship`. Campaign attribution resolves into the existing first-touch relationship; campaign provenance is stored on that relationship, while rewards continue to derive only from existing authoritative Finance commercial evidence and the append-only reward ledger. Expose the same projection through a first-class Filament partner workspace and the existing Inertia Portal/Mini App runtime.

**Tech Stack:** Laravel 13, PHP 8.5, Filament 5, PostgreSQL, PHPUnit, Inertia v3, Vue 3, TypeScript, Tailwind 4, Nutgram, Playwright.

**Spec:** `docs/product/requirements.md`, the owner-confirmed partner behavior in the task request, and `docs/architecture/adr/024-referral-reward-ledger-and-manual-payouts.md`.

## Global Constraints

- `organization_id` is the tenant/security boundary; never trust a request-supplied organization ID.
- `ClientReferralIdentity`, `ReferralRelationship`, Finance settlement evidence, reward ledger, payout state machine, and the existing Telegram/Portal runtime remain authoritative.
- Campaign links are not a second referral relationship and never rewrite an established first-touch relationship.
- Organic `source_detail` alone never qualifies a reward; only authoritative Finance commercial evidence does.
- Money is represented and displayed per currency; no cross-currency aggregation.
- Used links remain readable and are deactivated instead of deleted.
- Normal staff and partner actions are authorized server-side; UI visibility is not authorization.
- No provider payout, production deployment, real payout, destructive history rewrite, or unapproved promotional claim.
- New behavior is driven by failing PHPUnit/feature tests before implementation; PostgreSQL behavior is covered by integration/concurrency tests and heavy verification is hosted.
- Production source/config remains the repository; only the existing task branch and Draft PR #33 are changed.

---

### Task 1: Capture the verified CRM regression and establish the partner persistence boundary

**Files:**
- Create: `database/migrations/<timestamp>_create_referral_partner_and_campaign_tables.php`
- Create: `app/Modules/Referrals/Domain/Enums/ReferralPartnerStatus.php`
- Create: `app/Modules/Referrals/Domain/Enums/ReferralCampaignChannel.php`
- Create: `app/Modules/Referrals/Domain/Models/ReferralPartnerProfile.php`
- Create: `app/Modules/Referrals/Domain/Models/ReferralCampaignLink.php`
- Create: `app/Modules/Referrals/Domain/Models/ReferralLinkVisit.php`
- Modify: `app/Modules/Referrals/Domain/Models/ReferralRelationship.php`
- Modify: `app/Modules/Referrals/Domain/Models/ClientReferralIdentity.php`
- Modify: `app/Modules/Referrals/Domain/Models/ReferralCommercialEvidence.php`
- Create: `tests/Feature/ReferralRelationshipCrmRegressionTest.php`
- Create: `tests/Feature/ReferralPartnerPersistenceTest.php`

**Interfaces:**
- `ReferralPartnerProfile` belongs to one organization/client, has many campaign links, is unique per organization/client, and has active/inactive status plus activation/deactivation audit fields.
- `ReferralCampaignLink` belongs to a partner profile, partner client, and stable referral identity; it exposes `public_token`, human `name`, typed `channel`, active state, default flag, and disabled timestamp.
- `ReferralLinkVisit` contains only organization, campaign link, safe session hash, and occurrence time. The product metric is total `Переходы`.
- `ReferralRelationship` gains nullable campaign-link provenance without changing its existing uniqueness or first-touch constraints.
- All new foreign keys include organization scope, all token/index paths are indexed, and all new models use explicit casts/fillable policy matching sibling models.

- [ ] **Step 1: Write the failing relationship-page regression test.** Persist a relationship with the enum cast, render the real Filament list through Livewire, and assert the response is successful and shows the Russian establishment label.
- [ ] **Step 2: Run only that regression test and verify it fails with the enum/string callback type mismatch.**
- [ ] **Step 3: Write failing persistence tests.** Cover one profile per organization/client, distinct organization token uniqueness, campaign channel labels, historical link retention after disable, and organization-scoped foreign-key rejection.
- [ ] **Step 4: Run the persistence tests against the current schema and verify they fail because the new tables/columns do not exist.**
- [ ] **Step 5: Generate the migration with Artisan, then implement the enums and models.** Use PostgreSQL-safe composite foreign keys, checks for channel/status, indexes for organization/profile/token/link-time/relationship queries, and reversible schema operations.
- [ ] **Step 6: Fix the relationship resource formatter to accept both `ReferralEstablishmentMethod` and legacy string state, then run the regression and persistence tests.**
- [ ] **Step 7: Run Pint on changed PHP and commit the persistence boundary plus regression fix.**

### Task 2: Make partner activation, campaign-link lifecycle, and visits idempotent and tenant-safe

**Files:**
- Create: `app/Modules/Referrals/Application/ActivateReferralPartner.php`
- Create: `app/Modules/Referrals/Application/DeactivateReferralPartner.php`
- Create: `app/Modules/Referrals/Application/CreateReferralCampaignLink.php`
- Create: `app/Modules/Referrals/Application/DeactivateReferralCampaignLink.php`
- Create: `app/Modules/Referrals/Application/RecordReferralLinkVisit.php`
- Create: `app/Modules/Referrals/Application/ResolveReferralCode.php`
- Create: `app/Modules/Referrals/Application/ReferralCampaignLinkData.php`
- Create: `app/Http/Requests/CreateReferralCampaignLinkRequest.php`
- Modify: `app/Modules/Referrals/Application/EnsureReferralIdentity.php`
- Modify: `app/Http/Controllers/Portal/ReferralRedirectController.php`
- Create: `tests/Feature/ReferralPartnerLifecycleTest.php`
- Create: `tests/Integration/ReferralPartnerConcurrencyTest.php`

**Interfaces:**
- `ActivateReferralPartner::handle(Client $client, string $source, ?User $actor = null): ReferralPartnerProfile` locks the organization/client, is safe to repeat, ensures the stable identity, and creates one default “Личные рекомендации” link.
- `CreateReferralCampaignLink::handle(Client $client, string $name, ReferralCampaignChannel $channel, ?User $actor = null): ReferralCampaignLink` accepts only an active same-organization partner and retries unique token collisions without exposing IDs.
- `RecordReferralLinkVisit::handle(string $token, string $sessionId): ?ReferralCampaignLink` records a visit only for an active campaign/profile and never records IP/fingerprint data.
- `ResolveReferralCode::handle(int $organizationId, string $token): ReferralCampaignLink|ClientReferralIdentity|null` gives inactive campaign tokens precedence so a disabled token cannot fall through to a generic identity.

- [ ] **Step 1: Add failing activation/lifecycle tests.** Assert direct self-enrollment, CRM activation, repeated activation, default-link creation, inactive partner rejection, campaign name/channel validation, safe token shape, disabled-link preservation, and no visit for disabled/inactive links.
- [ ] **Step 2: Add failing tenant/concurrency tests.** Assert foreign client/profile/link access is denied, concurrent activation yields one profile/default link, concurrent link creation yields distinct tokens, and duplicate visits remain append-only without protected data.
- [ ] **Step 3: Run focused tests and confirm failures identify missing actions/schema.**
- [ ] **Step 4: Implement the actions with OrganizationContext, row locks, unique-constraint retry, authorization at the action boundary, audit events, and no controller-side business logic.
- [ ] **Step 5: Extend the existing referral redirect to call visit recording and preserve the existing portal redirect.** Keep generic identity links working.
- [ ] **Step 6: Run lifecycle and concurrency tests, then Pint.**
- [ ] **Step 7: Commit the partner lifecycle slice.**

### Task 3: Extend first-touch finalization and build bounded partner statistics

**Files:**
- Create: `app/Modules/Referrals/Application/GetReferralPartnerOverview.php`
- Create: `app/Modules/Referrals/Application/ListReferralPartnersForCrm.php`
- Create: `app/Modules/Referrals/Application/GetReferralPartnerWorkspace.php`
- Modify: `app/Modules/Referrals/Application/FinalizeClientAcquisition.php`
- Modify: `app/Modules/Referrals/Application/GetClientReferralOverview.php`
- Modify: `app/Modules/Referrals/Application/ListReferralRelationshipsForCrm.php`
- Modify: `app/Modules/Referrals/Domain/Models/ReferralRelationship.php`
- Create: `tests/Feature/ReferralCampaignAttributionTest.php`
- Create: `tests/Feature/ReferralPartnerStatisticsTest.php`

**Interfaces:**
- Campaign resolution in `FinalizeClientAcquisition` stores `referral_campaign_link_id` on the one authoritative relationship; invalid/disabled campaign tokens preserve non-referral UTM/source attribution but do not create a relationship.
- `GetReferralPartnerOverview::handle(Client $client): array` returns `isPartner`, compatibility `link`, `links`, six top-level stats, conversion percentages, referred clients with originating link/channel and authoritative paid state, per-currency balances, ledger history, and payout history.
- `GetReferralPartnerWorkspace::handle(User $actor, ReferralPartnerProfile|int $profile): array` returns the same bounded per-link/referred/reward/payout projection for CRM after organization and permission checks.
- Paid-client counts use distinct referred clients having at least one `ReferralCommercialEvidence` row; booking status alone is never sufficient.

- [ ] **Step 1: Write failing attribution tests.** Cover campaign-link capture/finalization, provenance on relationship, disabled-link rejection, historical provenance readability, first-touch protection against later campaign clicks, manual assignment authority, and same registration finalization exactly once.
- [ ] **Step 2: Write failing statistics tests.** Create multiple links/channels, visits, relationships, commercial evidence in two currencies, reward ledger entries, and payout requests; assert per-link/overall counts, conversions, and currency-separated balances.
- [ ] **Step 3: Run focused tests to confirm they fail before implementation.**
- [ ] **Step 4: Extend finalization with the resolver and relationship provenance while retaining existing attribution semantics and idempotent registration locks.**
- [ ] **Step 5: Implement bounded projection queries using grouped aggregates/subqueries and eager-loaded constrained relations; preserve existing `link`, `registrations`, and `rewards` compatibility fields for old clients.
- [ ] **Step 6: Run attribution/statistics tests and the existing referral reward suite.**
- [ ] **Step 7: Run Pint and commit the attribution/projection slice.**

### Task 4: Build the first-class CRM partner workspace and human client actions

**Files:**
- Create: `app/Filament/Resources/ReferralPartnerProfiles/ReferralPartnerProfileResource.php`
- Create: `app/Filament/Resources/ReferralPartnerProfiles/Pages/ListReferralPartnerProfiles.php`
- Create: `app/Filament/Resources/ReferralPartnerProfiles/Pages/ViewReferralPartnerProfile.php`
- Create: `app/Filament/Resources/ReferralPartnerProfiles/Schemas/ReferralPartnerProfileInfolist.php`
- Create: `app/Filament/Resources/ReferralPartnerProfiles/Tables/ReferralPartnerProfilesTable.php`
- Modify: `app/Filament/Resources/Clients/Pages/ViewClient.php`
- Create: `app/Modules/Referrals/Application/SearchActivePartnersForAssignment.php`
- Modify: `app/Modules/Referrals/Application/EstablishManualReferralRelationship.php`
- Modify: `app/Modules/Referrals/Application/SearchClientsForReferralAssignment.php`
- Modify: `app/Filament/Resources/ReferralRelationships/ReferralRelationshipResource.php`
- Create: `tests/Feature/ReferralPartnerCrmUxTest.php`
- Modify: `tests/Feature/ClientWorkspaceUxATest.php`

**Interfaces:**
- Filament navigation exposes `Клиенты -> Партнёры`, separate from the existing `Рекомендации` relationship audit view.
- Partner list rows show partner/status/visits/registrations/paid clients/available/pending/paid amounts without cross-currency totals and use stacked mobile presentation.
- Partner detail shows identity/status, links/per-link stats, referred clients, registration dates, origin channel, paid state, reward history, payout history, and visible activate/deactivate/link actions.
- Normal client view exposes `Сделать партнёром`, `Партнёрский кабинет`, and `Отключить партнёрскую программу` as obvious authorized header actions. The relationship assignment action is direct and says `Назначить партнёра`; the selector prioritizes active partners and preserves first-touch/manual authority.

- [ ] **Step 1: Add failing Livewire tests for the partner resource/list/detail and direct client actions.** Assert labels, authorization, tenant filtering, profile activation, workspace link, partner assignment, and preservation of the existing relationship page.
- [ ] **Step 2: Run those tests and verify missing resource/actions fail.**
- [ ] **Step 3: Implement the resource with application projections, bounded reads, responsive table columns, and direct primary actions.** Do not put partner business rules in Filament closures.
- [ ] **Step 4: Move the assignment action out of the generic additional-action group, relabel it, and use the active-partner search/application boundary.** Keep ManageClients authorization and reject self/cross-org/authoritative overwrite.
- [ ] **Step 5: Run CRM UX tests, the relationship regression, and Pint.**
- [ ] **Step 6: Commit the CRM workspace slice.**

### Task 5: Make the normal Finance acceptance path understandable without bypassing Finance

**Files:**
- Modify: `app/Filament/Support/FinancePresentation.php`
- Modify: `app/Filament/Support/FinancePaymentActions.php`
- Modify: `app/Filament/Resources/Bookings/Pages/ViewBooking.php`
- Modify: `app/Filament/Resources/Bookings/Schemas/BookingInfolist.php`
- Create or modify: `tests/Feature/FinanceCrmPaymentReadinessTest.php`
- Modify: `tests/Feature/FinanceCrmUxTest.php`

**Interfaces:**
- A legitimate completed booking with a positive configured service price continues to expose `Записать оплату` and uses the existing Finance ledger path.
- When no FinancialObligation exists, CRM sees a non-mutating human explanation derived from authoritative booking/service prerequisites (including incomplete visit and missing/non-positive price), not a fake settlement control or manufactured obligation.

- [ ] **Step 1: Write failing tests for the no-obligation readiness explanation and the existing legitimate payment action.** Use real booking lifecycle and service pricing fixtures.
- [ ] **Step 2: Run focused Finance tests and confirm the readiness surface is absent.**
- [ ] **Step 3: Implement a presentation-only readiness projection/action with exact current Finance prerequisite wording and preserve `FinancePaymentActions` authorization/visibility.**
- [ ] **Step 4: Run the Finance tests and relevant reward settlement tests.**
- [ ] **Step 5: Run Pint and commit the Finance UX remediation.**

### Task 6: Complete the Portal partner cabinet with multiple links and responsive share controls

**Files:**
- Create: `app/Http/Requests/ActivateReferralPartnerRequest.php` if request validation is needed for the route boundary
- Create: `app/Http/Requests/DeactivateReferralCampaignLinkRequest.php`
- Create: `app/Http/Controllers/Portal/ReferralPartnerController.php`
- Modify: `app/Http/Controllers/Portal/ReferralController.php`
- Modify: `app/Http/Controllers/Portal/ReferralPayoutController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `routes/web.php`
- Modify: `resources/js/types/portal.ts`
- Modify: `resources/js/locales/portal.ts`
- Modify: `resources/js/Components/Portal/AppShell.vue`
- Modify: `resources/js/Components/Portal/MobileBottomNavigation.vue`
- Modify: `resources/js/Pages/Portal/Home.vue`
- Modify: `resources/js/Pages/Portal/Referrals.vue`
- Modify: `resources/css/app.css` only if an existing portal token cannot express the layout
- Create: `tests/Feature/ReferralPartnerPortalTest.php`
- Modify: `tests/e2e/portal.spec.ts`

**Interfaces:**
- Unenrolled authenticated clients see `🤝 Стать партнёром`; active partners see `🤝 Партнёрский кабинет` in the existing Portal shell/home and can activate directly.
- The page title is `Партнёрский кабинет`; top metrics are `Переходы`, `Регистрации`, `Оплатили`, `Доступно`, `Ожидает выплаты`, `Выплачено`.
- `Мои ссылки` supports `Создать ссылку` with human name and Russian channel presets, displays a clean share URL, `Скопировать`, `Поделиться`, `Отключить`, and per-link visits/registrations/paid clients/reward totals by currency.
- Web Share API is used when available and clipboard is the fallback; technical IDs/tokens are not separately exposed.
- Existing payout request/cancel UI remains and uses per-currency balances; no promotional claims/copy are added.

- [ ] **Step 1: Add failing Inertia feature tests for unenrolled/enrolled state, activation, campaign create/disable, multiple channel links, share/copy URLs, stats, and payout request/cancel.**
- [ ] **Step 2: Run them and confirm missing routes/projection/UI fields fail.**
- [ ] **Step 3: Add typed request/controller routes that call Application actions and return redirects/Inertia props only.**
- [ ] **Step 4: Extend Portal shell props and replace the referrals page with a single-root, mobile-first partner cabinet using existing portal components/tokens.** Keep compatibility data for legacy referral URLs and use existing icon components for controls.
- [ ] **Step 5: Run frontend lint/type checks on changed files, targeted Portal tests, and Pint.**
- [ ] **Step 6: Commit the Portal slice.**

### Task 7: Extend the existing Telegram menu and protected Mini App launch

**Files:**
- Modify: `config/portal.php`
- Modify: `app/Modules/Channels/Application/GetTelegramMenu.php`
- Modify: `app/Modules/Channels/Application/ResolveTelegramMiniAppEntry.php`
- Modify: `app/Http/Controllers/Telegram/StartCommandController.php` or the existing Telegram start handler
- Modify: `routes/telegram.php`
- Modify: `tests/Feature/TelegramMiniAppLaunchTest.php`
- Modify: `tests/Feature/MilestoneTwoTelegramBotTest.php`
- Create: `tests/Feature/TelegramPartnerMenuTest.php`
- Modify: `tests/e2e/portal.spec.ts` or the existing Telegram Mini App E2E harness

**Interfaces:**
- The existing verified `initData` authentication/session path remains the only Mini App auth path.
- The existing `partner` content section remains available; a distinct protected cabinet destination is added through the same menu/launch abstraction.
- A verified non-partner receives `🤝 Стать партнёром`; a verified active partner receives `🤝 Партнёрский кабинет`; the launch lands on the real protected partner cabinet inside the Mini App.

- [ ] **Step 1: Add failing menu/launch tests for dynamic labels, protected destination, preserved partner content, and verified initData authentication.**
- [ ] **Step 2: Run them and confirm the allowlist/config/menu cannot resolve the partner cabinet.**
- [ ] **Step 3: Add the protected launch entry and pass the already-verified Telegram client into the existing menu projection; do not add a second bot/auth adapter.**
- [ ] **Step 4: Run Telegram feature tests and the existing Mini App launch tests.**
- [ ] **Step 5: Run frontend checks and commit the Telegram slice.**

### Task 8: PostgreSQL coverage, E2E flows, review, documentation, and candidate gates

**Files:**
- Create: `tests/Integration/ReferralPartnerPostgresTest.php`
- Create: `tests/Integration/ReferralPartnerConcurrencyTest.php`
- Modify: `tests/e2e/crm.spec.ts`
- Modify: `tests/e2e/portal.spec.ts`
- Modify: `docs/product/requirements.md`
- Modify: `docs/product/requirements-changelog.md`
- Modify: `CHANGELOG.md`
- Modify: `PROJECT_STATUS.md`
- Modify: `ROADMAP.md` only if the current milestone summary requires a state change

**Interfaces:**
- PostgreSQL tests cover profile/link/relationship/visit/reward/payout races and real FK/check/index semantics, including exact-once finalization and settlement reward behavior.
- Playwright tests actually click CRM activation/workspace/link/assignment/booking lifecycle/payment/payout controls, Portal activation/link/share/payout controls, and the Mini App launch/auth path where the existing harness can provide verified initData.
- Documentation distinguishes `IMPLEMENTED`, `VERIFIED`, and `OWNER ACCEPTED`; owner acceptance remains `NOT RUN` until staging UX is explicitly accepted.

- [ ] **Step 1: Add PostgreSQL integration/concurrency tests for tenant isolation, first touch, disabled links, historical provenance, counts, currencies, and payout reservation safety.**
- [ ] **Step 2: Add real browser-click E2E coverage and responsive assertions, including `document.documentElement.scrollWidth <= document.documentElement.clientWidth` at supported viewports.**
- [ ] **Step 3: Run focused SQLite/feature tests, Pint, Larastan, ESLint, `vue-tsc`, Vite build, and `git diff --check`; record any intentionally hosted-only checks accurately.**
- [ ] **Step 4: Dispatch the substantive read-only Codex review against the exact candidate range, classify findings as BLOCKING/REAL BUG/WORTHWHILE/COSMETIC, and fix all BLOCKING/REAL BUG findings.**
- [ ] **Step 5: Re-run fresh verification, commit, push the same branch, and update Draft PR #33.**
- [ ] **Step 6: Manually dispatch hosted exact-SHA CI, inspect every required job, and repeat only after batching real defects.**
- [ ] **Step 7: After green hosted CI, deploy the exact SHA to the same staging target, run migrations/health/smoke/Telegram checks, and use only normal CRM/Portal UI for the harmless acceptance fixture.**
- [ ] **Step 8: Update status with exact SHAs/run IDs/results, leave PR #33 Draft and production untouched, and do not mark owner acceptance complete.**
