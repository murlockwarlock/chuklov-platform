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

### 10.1 — AI Evaluation Quality / Observability

- Objective: extend the accepted M10 evaluation foundation into a deterministic, explainable quality-control workflow for practice owners and administrators.
- Starting SHA: `44a4ba09f01803a740b299786fabc6ad2a0ad49a`.
- Candidate branch: `codex/ai-eval-quality-observability`.
- Scope: typed server-owned assertions, structured/RAG quality results, immutable evaluation provenance, run-level cost/latency/failover metrics, human-review aggregates, compatible-run comparison, and bounded Russian Filament history/detail UX under `Искусственный интеллект → Проверки AI`.
- Non-goals: UX-C5 provider/model administration redesign, AI Companion, Telegram/Mini App chat, practitioner copilot or mutations, M11/M12 work, new provider catalog work, OQ-015 content, semantic judge scores, arbitrary scripting, generic BI, and real-provider CI evaluation.
- Affected requirements/modules: `REQ-AI-001`–`REQ-AI-008`, `REQ-RAG-001`–`REQ-RAG-004`; AI, Knowledge provenance, Organizations authorization, Security/privacy, and Filament CRM adapters.
- Data impact: additive evaluation-run provenance/metrics storage only; existing `AiRun`, `AiRunAttempt`, `AiRunRagReference`, and `AiRunHumanReview` remain the sources of execution, cost, retrieval, and review evidence. Historical runs are never recomputed from mutable suites, cases, releases, or pricing.
- Compatibility/privacy: preserve exact prompt/model pinning, synthetic-only input validation, organization-scoped composite ownership, encrypted protected trace access, sanitized errors, and normal fake-only AI tests. Assertion definitions and schemas are bounded and stored as evaluation evidence, not copied into logs or decrypted medical analytics.
- Verification: focused PHPUnit plus relevant AI/Knowledge/RAG tests, Pint, Larastan/PHP syntax, `git diff --check`, dependency/security audits, and any available PostgreSQL evaluation/concurrency integration target. Hosted CI, deployment, and merge are explicitly not part of this candidate.
