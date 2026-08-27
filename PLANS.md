# Implementation Plans

Use an active plan for non-trivial work spanning modules, migrations, security boundaries, external integrations, or multiple quality gates.

Each plan contains:

1. Objective and explicit non-goals.
2. Affected `REQ-*` IDs and modules.
3. Data/migration impact.
4. Compatibility, privacy, security, and organization-isolation risks.
5. Implementation sequence with verifiable checkpoints.
6. Unit, feature, integration, security, and E2E tests.
7. Documentation and ADR changes.
8. Final quality gate and rollback considerations.

Keep only current/relevant plans here. Completed plans are removed after outcomes are reflected in code, tests, ADRs, CHANGELOG, ROADMAP, and PROJECT_STATUS.

## Active Plans

### M11D — B2B lead funnel / Zoom sales handoff — implementation candidate

- Objective: implement `REQ-B2B-001` as one Phase 1 B2B acquisition vertical slice for explicit specialist segmentation, the Telegram/Portal bot-sales CTA, durable leads, shared specialist scheduling, Zoom provisioning, and bounded CRM operations.
- Non-goals: Phase 2 white-label/SaaS, tenant provisioning or billing, referral economics, M12 subscriptions, real payment providers, unrelated CRM cleanup, and independent Zoom calendar authority.
- Architecture: B2B SalesCall is the local business source of truth; a provider-neutral `VideoMeetingProvider` boundary and Zoom Server-to-Server OAuth adapter are an external projection. SalesCall occupancy is a typed linked projection through the existing `UnavailablePeriod` scheduling authority, with the established Specialist lock discipline.
- Data/security: all durable B2B records are organization-owned and client-scoped; identity/contact data is reused from existing projections; no medical payload is copied; provider secrets remain in organization credentials; external provider calls occur after local transactions and use durable operation fencing/retry state.
- Verification: focused B2B PHPUnit passes 23 tests / 148 assertions, affected regression tests pass 114 tests / 787 assertions, and PostgreSQL coverage exists but is `EXISTS — NOT RUN LOCALLY` because the configured PostgreSQL server is unavailable. Pint, changed PHP syntax, scoped PHPStan, ESLint, `vue-tsc`, Vite build, and `git diff --check` are required final checks; hosted CI, staging, deployment, merge, and owner/manual acceptance are not claimed.

### UX-A — Client Workspace + ordinary client/global search

- Objective: make the opened Client the daily CRM workspace and make the Clients list and global search bounded identity-only finders.
- Starting SHA: `90ceb3c5da1dd970315f2d82512e1cf6a11119e9`.
- Candidate branch: `ux-a-client-workspace-search`.
- Scope: ordinary client search, canonical phone search key and indexes, resumable phone backfill, composed Client Clinical Cockpit, bounded owning-module reads, contextual medical/booking restriction actions, and fail-closed tenant resolution for medical attachment uploads.
- Non-goals: UX-A2 controlled medical discovery, medical blind indexes/taxonomy, public media UX, theme work, CRM-wide IA cleanup, finance redesign, AI productization, and M11.
- Architecture: no CRM domain module or cross-module repository. Identity, MedicalProfiles, Attachments, Sessions, Scheduling, Surveys, and Finance retain ownership of their authorized Application reads; Filament composes them lazily into bounded workspace sections.
- Data impact: nullable `clients.phone_search_key`, organization/phone B-tree index, PostgreSQL trigram extension/indexes for ordinary name/email fragments, and a bounded `clients:backfill-phone-search-keys` command. The migration performs no legacy bulk rewrite.
- Privacy/performance: global search contains no medical data; medical profile decryption is explicit and bounded; attachment metadata is paginated and access URLs are generated only on an authorized user action; all client datasets remain organization-scoped and server-side.
- Verification: focused PHPUnit, Larastan, Pint, PHP syntax, `git diff --check`, and Playwright test listing are run locally. PostgreSQL-specific integration, hosted CI, and full browser execution remain pending. This is an implementation candidate pending independent acceptance; do not mark UX-A closed.
