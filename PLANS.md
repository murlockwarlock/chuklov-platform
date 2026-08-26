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

### M11B — Broadcast Engine, segmentation, and marketing governance

- Objective: implement `REQ-BROADCAST-001`–`REQ-BROADCAST-003` as a stacked candidate on M11A, reusing the M5 provider-neutral channel/delivery boundary.
- Remediation starting SHA: `170ed7784e5fda5401e11d54ebaabd54332edd62`; candidate branch: `codex/m11b-broadcast-engine`; exact stacked base SHA: `45b3eb0189cab63ad60309474c4bb614b4bf1f15` on `codex/m11a-acquisition-referrals-feedback`.
- Scope: authorized CRM drafts, RU/EN immutable marketing template versions, typed allowlisted filters, preview/test send, deterministic audience materialization, immediate/scheduled dispatch, bounded batches, verified-channel and affirmative marketing-consent eligibility, durable claims/idempotency, sanitized outcomes, audit evidence, and PostgreSQL concurrency coverage.
- Data impact: additive organization-scoped campaign, immutable audience snapshot, batch, recipient, delivery-attempt, and minimal Client broadcast-classification tables with composite tenant foreign keys and explicit PostgreSQL-safe identifiers.
- Remediation: production snapshots are bound to exact draft revisions; delivery attempts are created before channel I/O and Telegram acknowledgement ambiguity is terminal unknown with no blind replay; scheduler/queue recovery is PostgreSQL-state-driven with bounded backoff and fenced leases; execution revalidates creator authority, consent, verified targets, and active marketing templates.
- Governance: marketing templates are Broadcast-only with a capability-aware variable allowlist; scenario rules remain service/transactional-only; organization-timezone wall-clock scheduling converts to authoritative instants; recipient history is paginated and sanitized.
- Privacy/security: clinical and free-text health targeting fails closed; survey completion may be used as a non-clinical engagement fact, while encrypted survey results/categories remain unavailable pending separate legal/privacy approval and OQ-015-backed content. `REQ-NOTIFY-008` remains future and no preference center, quiet hours, frequency cap, or new unsubscribe semantics are introduced.
- Non-goals: M11C analytics, reward economics/OQ-007, M12–M14, real providers, AI workflows, and fabricated survey definitions or result meanings.
- Verification: focused Unit/Feature and M5/M11A regressions, changed-code Larastan/Pint, frontend/build/audits, migration identifier/static preflight, and real PostgreSQL race tests wired to the hosted concurrency scope. No hosted CI, staging, deployment, or merge is authorized for this task.

### M11A — Attribution, referral tracking, and NPS/feedback foundation

- Objective: implement the unblocked M11A acquisition/referral/feedback foundation for `REQ-ATTRIBUTION-001`, `REQ-REFERRAL-001`, `REQ-FEEDBACK-001`, and `REQ-PORTAL-006` while preserving the modular-monolith boundaries and existing M2/M5/M6/M7/M10 foundations.
- Starting SHA: `6df5ac5b2c95adf6faf7a69a3b551d2c0f1cb713`.
- Candidate branch: `codex/m11a-acquisition-referrals-feedback`.
- Scope: bounded first-touch/pre-auth attribution, manual-source fallback, stable opaque referral identities, durable acquisition proof, product-neutral automatic/manual relationships, neutral post-commit finance evidence, organization-scoped NPS configuration/submissions, encrypted internal feedback, Portal/Mini App and authorized CRM visibility, and additive compatibility adoption for legacy Client fields.
- Non-goals: referral reward amount/points/currency/expiry/redemption/cash-out/refund-reversal semantics, reward ledger, Broadcast Engine, campaign scheduler, segmentation, marketing-consent preference center, analytics dashboard, bulk marketing, and any M8/OQ-015 content. OQ-007 remains open and blocks only the reward-ledger semantics.
- Data impact: additive PostgreSQL organization-scoped attribution, pre-auth handoff, acquisition proof, referral identity/relationship, module-neutral integration event, neutral commercial evidence, and feedback configuration/submission tables with composite tenant constraints and bounded indexes. Legacy `clients.lead_source` and `clients.referral_code` remain preserved compatibility fields; new M11A behavior reads normalized attribution/referral tables.
- Privacy and security: organization context is server-derived; automatic attribution is allowlisted and bounded; first-touch is immutable after acceptance; referral claims are same-organization, self-referral-resistant, and idempotent; feedback text uses Laravel encrypted casting and never enters audit/log metadata or global search.
- Verification: focused PHPUnit, affected Identity/Portal/Telegram/Scenario/Finance regressions, Pint, syntax, Larastan, frontend lint/typecheck/build, and diff checks are required locally. PostgreSQL constraint/concurrency tests are present but are `EXISTS — NOT RUN LOCALLY` under the repository's SQLite test configuration. Hosted CI, deployment, staging, and merge remain out of scope.

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
