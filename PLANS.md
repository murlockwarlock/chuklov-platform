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
- Verification: candidate starts exactly at `621e1f7c3fc4e4c667e5d11d870b810e735152e1` after fresh full re-review CHANGES REQUIRED and adversarial confirmation of `M11D-AI-LOCK-001` through the committed-intervening-reassignment stale-snapshot schedule. This remediation establishes target credential `FOR UPDATE` → provider configuration update order, configures bounded `attempts: 3` retries on both outer `ConnectAiProvider` transactions, and adds process-level PostgreSQL lock-order evidence to the existing M10 concurrency suite. Focused local AI/security checks pass; PostgreSQL is `NOT RUN LOCALLY` because PHPUnit forces SQLite, no Docker services are running, and no local `127.0.0.1:5432` listener exists. The resulting SHA is not independently reviewed; hosted CI and PostgreSQL runtime proof remain pending; staging remains exactly `3dc9f8b9a4038831823a687fa53abe6f481302b0`; M11 remains IN_PROGRESS; M12 remains NOT_STARTED; OQ-007 remains OPEN; the ordinary Booking AUTO/MANUAL Phase-1 gap remains open; and PR #23 remains untouched.

### M11D AI — retry-safety and explicit credential detach follow-up

- This bounded follow-up starts exactly at `4ea1fa5e490345bd5ef4ae1b6513e25ae20cce32` after fresh review CHANGES REQUIRED. It remediates only mutable provider-object retry state in `ConnectAiProvider::update()` and explicit null/empty credential detach; the captured `$data` array and `create()` retry path are not treated as defective. The existing exact-state PostgreSQL polling remains unchanged non-blocking P3 debt; focused local checks pass, PostgreSQL and hosted CI remain pending, and the resulting SHA is not independently re-reviewed.

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
