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

### M11C — CRM analytics dashboard — implementation candidate

- Objective: implement `REQ-CRM-002` as read-only aggregate projections on the existing Filament `Инфопанель`, preserving the accepted M11A/M11B source semantics and the existing `UpcomingBookingsWidget`.
- Starting SHA: `42c1d66987862fa819d581a803995fdfab2b8b7b`; branch: `codex/m11c-crm-analytics-dashboard`.
- Scope: one shared organization-timezone period filter, acquisition/source, scheduling, finance, AI-failure, and knowledge-ingestion widgets with least-privilege permissions and deliberate empty states.
- Metric authority: Client creation and normalized first-touch attribution; Booking creation and immutable BookingEvent history; completed BookingEvent transitions; HOME_VISIT Booking rows; signed base-currency Finance ledger/obligation records; logical AiRun and KnowledgeIngestionRun failures. Retention is explicitly operational rebooking after a completed visit; LTV is historical realized cohort value, not a forecast.
- Architecture and privacy: SQL aggregates query authoritative organization-scoped records directly, with no Analytics tables, ETL, cached business truth, source mutation, payload decryption, raw failure text, or sensitive health content. Finance fails closed when existing currency/reconciliation authority is invalid.
- Outcome: implementation is complete for independent review. Focused M11C Unit/Feature tests, Dashboard/upcoming-booking coverage, M11A/M11B, Scheduling, AI, and Knowledge regressions, Pint, syntax, Larastan, frontend checks, audits, and diff checks pass; the standalone Finance regression batch has one unrelated SQLite decimal-format assertion failure. PostgreSQL integration remains `EXISTS — NOT RUN LOCALLY` in the SQLite-only environment. No hosted CI, staging, deployment, merge, or M12 work is included.

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
