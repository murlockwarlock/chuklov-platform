# Project Status

- Last updated: 2026-08-12
- Current phase: Phase 1 foundation
- Current milestone: Milestone 2 — Client Portal + Telegram Foundation
- Status: DONE — M2 scope is implemented and locally verified

## Completed Remediation

- M0-AUDIT-H01/H02 remediated with a deny-by-default Docker context, deterministic privacy regression, and repeat-safe `APP_KEY` initialization.
- M0-AUDIT-M01 through M06 remediated: expanded CI, Filament tenant/workflow coverage, fake-only Nutgram handler evidence, guarded User privilege fields, local unreachable-object pruning, and privacy-policy correction.
- Safe LOW cleanup completed: skill link/size cleanup, port alignment, demo/redundant scaffold removal, and Horizon snapshot scheduling.
- Local quality, integration, Playwright, privacy/secret, Docker build, runtime health, and Horizon gates pass.
- Fresh clone of remediation commit `233ffcc7604a08638d05195333a5c0f1e13b1f1b` completed isolated setup, migrations/seed/build, key idempotence, integration tests, dependency health, and Horizon runtime checks.
- Hosted GitHub Actions passed for remediation revision `881c6ac5d3ee8d4ef1bcce6ee8e69b4d904dea64`: quality/integration, Playwright desktop/mobile, privacy/secret, and Docker runtime/Horizon.

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
- Added deterministic organization/client uniqueness, progressive profile confirmation, versioned onboarding progress, a localized configurable Telegram menu, and the capability-aware channel boundary.
- Added organization/client-scoped conversations and normalized idempotent message persistence with an allowlisted metadata shape.
- Added organization-scoped versioned legal documents, immutable published versions, exact-version portal consent evidence, and platform-controlled Phase 1 legal management; organizations cannot self-enable organization-managed wording.
- Established canonical PostgreSQL timezone-aware M2 instants, IANA timezone validation/default resolution, centralized portal date/time formatting (`DD-MM-YYYY`, `HH:mm`), and future calendar/email readiness boundaries.
- No medical, survey, scheduling, payment, AI, broadcast, subscription, MAX, Instagram, or other later-milestone behavior was added.

## Blockers / Open Questions

- OQ-001 and OQ-006 are resolved for M2 by the owner-confirmed decisions recorded in `docs/product/open-questions.md`.
- Remaining open questions concern later milestone scope and do not block completed M2 behavior.

## Important Decisions

- Docker build context is deny-by-default; only reviewed runtime Dockerfiles are allowed.
- Routine setup never rotates a nonblank `APP_KEY`; deliberate rotation is a separate authorized operation.
- M0 hosted CI separates quality/integration, Playwright, privacy/secret, and Docker runtime gates for diagnosability.
- Client source documents and full chat history remain local-only; normalized REQs and sanitized repository docs are the Git development source.
- M1 keeps `organization_id` as the security boundary, derives runtime context from server configuration/membership, and does not introduce `master_id`, heavy tenancy, SaaS provisioning, or provider integrations.
- M1 credentials use Laravel encrypted casts and are only exposed through masked representations; audit metadata is action-allowlisted before persistence.

## Latest Verified Local Quality Gate

2026-08-12: `composer validate --strict` passed. The single final `make ci` passed: 8 unit tests/19 assertions, 61 feature tests/335 assertions, 11 PostgreSQL integration tests/32 assertions, Pint, Larastan with 0 errors, TypeScript, Vite build, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities. ESLint was rerun after a template-formatting warning and passed cleanly. Separate M2 focused coverage passed with 27 tests/180 assertions plus 5 PostgreSQL M2 database tests/15 assertions. Playwright desktop/mobile smoke passed with 2 tests. M0 and M1 were not re-audited in M2; hosted verification is recorded in `MILESTONE_2_REPORT.md` after push.
