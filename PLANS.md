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

### 10.2 — AI Companion remediation

- Objective: remediate the accepted Stage 10.2 candidate without broadening scope: adopt deterministic legacy M2 history, fence turn terminal writes, durably seal Telegram albums, prevent blind replay after uncertain Telegram sends, remove implicit image reuse and client-facing internal IDs, bound exports, and restore truthful status documents.
- Starting SHA: `b6f885e6e0156ce4bab767a40484a6d19edc01b2`.
- Candidate branch: `codex/ai-companion`.
- Non-goals: OQ-015 clinical prompt content, voice/TTS, MAX/Instagram, general staff copilot, arbitrary CRM mutations, a second AI/RAG/conversation subsystem, hosted CI, deployment, staging, merge, and M11.
- Data impact: additive adoption state and binding/index changes, encryption of adopted legacy Companion messages in bounded batches, explicit assembling/uncertain states, recent-image reference mode, and delivery uncertainty timestamps. Existing message IDs/provenance are preserved; ambiguous M2 rows remain legacy.
- Boundaries: all execution uses `AiWorkflowEngine` and `AiCapability::ClientCompanion`; image turns require existing `ImageInput` modality candidates; private cleared image storage and the medical encryption/protected-trace boundary are reused; jobs carry identifiers only.
- Verification: focused Companion, Conversation, Portal, Telegram, AI, Knowledge/RAG, privacy, static, frontend, syntax, Blade, and dependency checks are required locally. PostgreSQL integration/concurrency is required where available; hosted CI, staging, deployment, and merge remain explicitly out of scope.

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
