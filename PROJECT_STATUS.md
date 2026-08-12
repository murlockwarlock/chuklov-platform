# Project Status

- Last updated: 2026-08-12
- Current phase: Phase 1 foundation
- Current milestone: Milestone 1 — Organizations / Identity / Settings / Security Foundations
- Status: DONE — M1 remediation locally complete; hosted verification pending for the pushed revision

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

## Next

- Resolve OQ-001 before implementing ordinary client web authentication in M2.
- Begin M2 only under its approved scope; Telegram onboarding and ordinary web authentication remain deferred.

## Blockers / Open Questions

- OQ-001 ordinary client web authentication mechanism remains open.
- OQ-006 legal consent texts, jurisdictions, lawful basis, retention, and approved versions remain open.

## Important Decisions

- Docker build context is deny-by-default; only reviewed runtime Dockerfiles are allowed.
- Routine setup never rotates a nonblank `APP_KEY`; deliberate rotation is a separate authorized operation.
- M0 hosted CI separates quality/integration, Playwright, privacy/secret, and Docker runtime gates for diagnosability.
- Client source documents and full chat history remain local-only; normalized REQs and sanitized repository docs are the Git development source.
- M1 keeps `organization_id` as the security boundary, derives runtime context from server configuration/membership, and does not introduce `master_id`, heavy tenancy, SaaS provisioning, or provider integrations.
- M1 credentials use Laravel encrypted casts and are only exposed through masked representations; audit metadata is action-allowlisted before persistence.

## Latest Verified Local Quality Gate

2026-08-12: `composer validate --strict` passed. Final `make ci` passed: 4 unit tests/12 assertions, 32 feature tests/147 assertions, 6 PostgreSQL integration tests/17 assertions, Pint, Larastan with 0 errors, ESLint, TypeScript, Vite build, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities. Focused PostgreSQL remediation tests passed separately: 27 tests/134 assertions. Playwright, Docker build/runtime, privacy, and secret scans were not rerun because M1 remediation did not change those M0 surfaces; no fresh-clone verification or M0 re-audit was performed.
