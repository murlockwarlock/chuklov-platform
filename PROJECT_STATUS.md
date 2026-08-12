# Project Status

- Last updated: 2026-08-13
- Current phase: Phase 1 foundation
- Current milestone: Milestone 3 — CRM Core / Clients / Services / Specialists
- Status: DONE — M3 implementation and required local quality gates passed; hosted verification is recorded in the local M3 report

## Completed Remediation

- M0-AUDIT-H01/H02 remediated with a deny-by-default Docker context, deterministic privacy regression, and repeat-safe `APP_KEY` initialization.
- M0-AUDIT-M01 through M06 remediated: expanded CI, Filament tenant/workflow coverage, fake-only Nutgram handler evidence, guarded User privilege fields, local unreachable-object pruning, and privacy-policy correction.
- Safe LOW cleanup completed: skill link/size cleanup, port alignment, demo/redundant scaffold removal, and Horizon snapshot scheduling.
- Local quality, integration, Playwright, privacy/secret, Docker build, runtime health, and Horizon gates pass.
- Fresh-clone and hosted checks from earlier milestones remain historical evidence; their pre-rewrite SHA values are not acceptance revisions.

## Milestone 1

- Implemented organization memberships with owner/administrator/staff roles, context-bound policies, client identity foundations, typed settings and feature controls, encrypted rotatable credentials, safe audit events, and log redaction.
- Added PostgreSQL-oriented constraints, composite organization/client foreign keys, ownership indexes, and a legacy-user membership backfill without rewriting M0 migration history.
- Added focused organization isolation, IDOR, membership/RBAC, mass-assignment, feature/settings, identity, credential, audit, and logging regression coverage.
- M0 remains accepted; no M0 re-audit or fresh-clone verification was performed in this milestone.

## Milestone 1 remediation

- Corrected the PostgreSQL Filament/Portal regression by binding the test to the same server-derived organization used by request middleware; no tenant boundary was weakened.
- Deferred destructive legacy-user column removal to a later contraction release; populated PostgreSQL backfill coverage verifies administrator/staff roles, idempotence, legacy-column retention, and multi-membership preservation.
- Made audited M1 mutations transactional, replaced denylist audit sanitization with action-specific metadata allowlists, enforced the Service Catalog entitlement in Application and policy paths, hardened sensitive model state against mass assignment, and redacted complete authentication values across configured log channels.
- Existing milestone report/review/audit Markdown artifacts are ignored and removed from the repository; the remediation report is local-only.

## Milestone 2

- Implemented the shared responsive Client Portal path for desktop, mobile browser, and Telegram Mini App runtime mode through the same Inertia/Vue pages and Application actions.
- Added a centralized restrained health/wellness visual foundation for the portal: warm-neutral canvas, sage brand, terracotta accent, typography, spacing, surfaces, borders, radii, controls, feedback states, and responsive layout primitives; CRM styling remains independent.
- Added server-verified Telegram initData authentication with signature validation through Nutgram, freshness and replay controls, session regeneration, organization-scoped client resolution, and redacted audit metadata.
- Added passwordless email authentication with normalized identities, hashed single-use codes, expiry, bounded attempts/rate limits, provider-neutral delivery, and session regeneration.
- Added verified Telegram identity linking/reuse through short-lived server tokens and authentic bot evidence; token replay, forged identity, cross-organization, and frontend-override paths are rejected.
- Remediated M2 replay canonicalization, fail-closed authentication mail transport handling, concurrent Telegram link initiation, and concurrent legal publication with PostgreSQL invariants and stable-row serialization.
- Removed tracked private master/report documents from the current index and rewritten reachable history; RECHECK, review/report/audit, master-plan, and source-requirement policies are now ignored while local copies remain available.
- Added deterministic organization/client uniqueness, progressive profile confirmation, versioned onboarding progress, a localized configurable Telegram menu, and the capability-aware channel boundary.
- Added organization/client-scoped conversations and normalized idempotent message persistence with an allowlisted metadata shape.
- Added organization-scoped versioned legal documents, immutable published versions, exact-version portal consent evidence, and platform-controlled Phase 1 legal management; organizations cannot self-enable organization-managed wording.
- Established canonical PostgreSQL timezone-aware M2 instants, IANA timezone validation/default resolution, centralized portal date/time formatting (`DD-MM-YYYY`, `HH:mm`), and future calendar/email readiness boundaries.
- No medical, survey, scheduling, payment, AI, broadcast, subscription, MAX, Instagram, or other later-milestone behavior was added.

## Blockers / Open Questions

- OQ-001 and OQ-006 are resolved for M2 by the owner-confirmed decisions recorded in `docs/product/open-questions.md`.
- Remaining open questions concern later milestone scope and do not block completed M2 behavior.

## Milestone 3

- Added organization-scoped Filament CRM resources and Application actions for Clients, Service/catalog records, Specialists/Practitioners, and managed localized portal content.
- Client CRM edits preserve verified channel identity semantics and provide audited self-service booking restriction history; no medical fields were added.
- Specialists remain distinct organization-owned identities. Optional staff links use active same-organization `OrganizationMembership` rows and composite database ownership constraints; `users.organization_id` is not used.
- Service catalog configuration supports localized content, category, timing, formats, active state, catalog type, payment policy, and integer minor-unit prices with explicit currency. Products have no commerce workflow.
- No scheduling, booking, payment, medical, notification/scenario, AI, RAG, broadcast, subscription, external-channel, or Phase 2 SaaS behavior was added.

## Important Decisions

- Docker build context is deny-by-default; only reviewed runtime Dockerfiles are allowed.
- Routine setup never rotates a nonblank `APP_KEY`; deliberate rotation is a separate authorized operation.
- M0 hosted CI separates quality/integration, Playwright, privacy/secret, and Docker runtime gates for diagnosability.
- Client source documents and full chat history remain local-only; normalized REQs and sanitized repository docs are the Git development source.
- M1 keeps `organization_id` as the security boundary, derives runtime context from server configuration/membership, and does not introduce `master_id`, heavy tenancy, SaaS provisioning, or provider integrations.
- M1 credentials use Laravel encrypted casts and are only exposed through masked representations; audit metadata is action-allowlisted before persistence.

## Latest Verified Local Quality Gate

2026-08-13: `composer validate --strict` passed. The final local `make ci` passed: 9 unit tests/21 assertions, 79 feature tests/399 assertions, 22 PostgreSQL integration tests/44 assertions, Pint, Larastan with 0 errors, TypeScript, Vite build, Composer audit with 0 advisories, npm audit with 0 vulnerabilities, and clean ESLint. M3 focused feature coverage passed with 15 tests/47 assertions; the PostgreSQL M3 integration suite passed with 22 tests/44 assertions. `npm run test:e2e` passed on desktop and mobile after applying the pending additive migrations to the local runtime database. `make scan-secrets` passed with no leaks. M0–M2 were not re-audited.
