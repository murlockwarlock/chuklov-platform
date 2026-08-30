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

### M11D Playwright contract + fixture remediation

- Objective: repair only the stale Playwright UI contracts and the E2E fixture prerequisites exposed by hosted run `33316506558`; do not change production behavior, CI wiring, database defaults, or accepted M11D concurrency architecture.
- Starting SHA: `68a55d101a42d4e82e084c5c215b292f0d06447d`. Hosted full CI run `33315885720` and PostgreSQL concurrency run `33315522045` remain green for that candidate; hosted E2E recorded `28` passed, `16` failed, and `0` skipped tests across Chromium desktop and WebKit mobile.
- Remediation: align CRM headings/tabs/sidebar links and the Scenario delay field with their current accessible labels; complete the mocked authenticated `Portal/Home` contract; use an exact Profile heading locator; and configure the canonical test-only medical key before encrypted companion fixture creation. Preserve B2B E2E coverage and all functional assertions without timeout increases, sleeps, or skips.
- Verification state: local PostgreSQL/Redis and browser execution are unavailable; changed-spec ESLint, `vue-tsc`, Playwright discovery, and `git diff --check` pass. The resulting SHA requires new exact-SHA hosted E2E and full-CI proof; staging remains exactly `3dc9f8b9a4038831823a687fa53abe6f481302b0`; M11 remains `IN_PROGRESS`; M12 remains `NOT_STARTED`; OQ-007 remains `OPEN`; the ordinary Booking AUTO/MANUAL Phase-1 gap remains open; and PR #23 remains untouched.

### M11D Zoom PostgreSQL test-barrier remediation

- Objective: repair only the PostgreSQL Zoom concurrency test harness's lossy readiness-token observation; do not change production Zoom, credential, B2B SalesCall, Makefile, CI, SQLite, or database configuration behavior.
- Starting SHA: `ad12d6cb5000467b54b41edcef50c59104d993a7`. The narrow independent Filament re-review was `GO`; hosted exact-SHA PostgreSQL run `33313698105` recorded `65` passed, `3` failed, `415` assertions, `0` skips, and `142.09s`, with only the three approximately 30-second Zoom readiness waits failing.
- Remediation: retain the parent credential `FOR UPDATE` and organization `FOR NO KEY UPDATE` barriers, start both real child processes, and release the parent transaction only after exact readiness tokens are found in cumulative STDERR. The 30-second process timeout and bounded PostgreSQL lock diagnostics remain unchanged.
- Verification state: PostgreSQL is `NOT RUN LOCALLY` because `pg_isready` is unavailable and no local `127.0.0.1:5432` listener exists. The resulting SHA is not yet hosted-PostgreSQL proven; hosted CI/full CI, deployment, and merge remain not run. Staging remains exactly `3dc9f8b9a4038831823a687fa53abe6f481302b0`; M11 remains `IN_PROGRESS`; M12 remains `NOT_STARTED`; OQ-007 remains `OPEN`; the ordinary Booking AUTO/MANUAL Phase-1 gap remains open; and PR #23 remains untouched.

### M11D — B2B lead funnel / Zoom sales handoff — implementation candidate

- Objective: implement `REQ-B2B-001` as one Phase 1 B2B acquisition vertical slice for explicit specialist segmentation, the Telegram/Portal bot-sales CTA, durable leads, shared specialist scheduling, Zoom provisioning, and bounded CRM operations.
- Non-goals: Phase 2 white-label/SaaS, tenant provisioning or billing, referral economics, M12 subscriptions, real payment providers, unrelated CRM cleanup, and independent Zoom calendar authority.
- Architecture: B2B SalesCall is the local business source of truth; a provider-neutral `VideoMeetingProvider` boundary and Zoom Server-to-Server OAuth adapter are an external projection. SalesCall occupancy is a typed linked projection through the existing `UnavailablePeriod` scheduling authority, with the established Specialist lock discipline.
- Data/security: all durable B2B records are organization-owned and client-scoped; identity/contact data is reused from existing projections; no medical payload is copied; provider secrets remain in organization credentials; external provider calls occur after local transactions and use durable operation fencing/retry state.
- Verification: candidate starts exactly at `621e1f7c3fc4e4c667e5d11d870b810e735152e1` after fresh full re-review CHANGES REQUIRED and adversarial confirmation of `M11D-AI-LOCK-001` through the committed-intervening-reassignment stale-snapshot schedule. This remediation establishes target credential `FOR UPDATE` → provider configuration update order, configures bounded `attempts: 3` retries on both outer `ConnectAiProvider` transactions, and adds process-level PostgreSQL lock-order evidence to the existing M10 concurrency suite. Focused local AI/security checks pass; PostgreSQL is `NOT RUN LOCALLY` because PHPUnit forces SQLite, no Docker services are running, and no local `127.0.0.1:5432` listener exists. The resulting SHA is not independently reviewed; hosted CI and PostgreSQL runtime proof remain pending; staging remains exactly `3dc9f8b9a4038831823a687fa53abe6f481302b0`; M11 remains IN_PROGRESS; M12 remains NOT_STARTED; OQ-007 remains OPEN; the ordinary Booking AUTO/MANUAL Phase-1 gap remains open; and PR #23 remains untouched.

### M11D AI — retry-safety and explicit credential detach follow-up

- This bounded follow-up starts exactly at `4ea1fa5e490345bd5ef4ae1b6513e25ae20cce32` after fresh review CHANGES REQUIRED. It remediates only mutable provider-object retry state in `ConnectAiProvider::update()` and explicit null/empty credential detach; the captured `$data` array and `create()` retry path are not treated as defective. The existing exact-state PostgreSQL polling remains unchanged non-blocking P3 debt; focused local checks pass, PostgreSQL and hosted CI remain pending, and the resulting SHA is not independently re-reviewed.

### M11D Filament — fresh edit-record follow-up

- This bounded follow-up starts exactly at `19f04cc0cbcad95d32717c767ff20579b9126297` after fresh exact-SHA review returned CHANGES REQUIRED only for `M11D-FILAMENT-RECORD-001` (P2). It replaces the Filament edit page record with the authoritative `ConnectAiProvider` result before post-save hooks/events and adds a real Livewire page/event regression. No AI action, credential lock, detach, PostgreSQL concurrency test, Makefile, or CI wiring is changed; the resulting SHA is not independently re-reviewed, PostgreSQL remains pending, hosted CI and deployment are not run, staging remains `3dc9f8b9a4038831823a687fa53abe6f481302b0`, M11 remains IN_PROGRESS, M12 remains NOT_STARTED, OQ-007 remains OPEN, and PR #23 remains untouched.

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
