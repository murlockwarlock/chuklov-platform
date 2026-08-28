# Project Status

## 2026-08-28 — M11D CI test-isolation remediation candidate

- The Round 3 application candidate received independent `GO`. M11 remains `IN_PROGRESS` and `REQ-B2B-001` remains `CANDIDATE`.
- Hosted full CI run `33124975103` executed against exact SHA `a7d023265f82fff5d72cd72d5fa100c3ece1adc6`; GitHub jobs were green, but M11D acceptance CI was `NOT ACCEPTED` because `tests/Integration/B2bSalesCallPostgresTest.php` was omitted from hosted PostgreSQL test selection. Required M11D PostgreSQL proof remains `NOT ESTABLISHED`.
- CI-wiring candidate `31cf14c8a3f74cb656c2e4c20f31d43d0087e8e3` permanently added the file for a fresh exact-SHA gate. The narrow CI review found `CI-001`: the newly reachable provider-lease test was not isolated from synchronous provider dispatch. This test-harness remediation isolates that provider job for a fresh narrow review. M11D acceptance remains `NOT ESTABLISHED`; PostgreSQL remains `NOT PROVEN` in hosted CI and `NOT RUN LOCALLY`; staging remains `NOT DEPLOYED`; owner/manual acceptance remains `NOT DONE`.
- OQ-007 remains OPEN, OQ-015 remains unresolved, M12 remains `NOT_STARTED`, and PR #23 remains untouched.

## 2026-08-28 — M11D independent-review remediation round 2 candidate

- Round 2 starts exactly at reviewed candidate SHA `a1053c9eb94ce2a0a1ec69946c53f1ae15070e1a` on `codex/m11d-b2b-lead-funnel`, with accepted M11C preserved at main SHA `978eca9b86cb88f9a5b02b66cc28fe2d8b60026f`. M11 remains `IN_PROGRESS` and `REQ-B2B-001` remains `CANDIDATE`.
- The bounded remediation addressed the independent re-review findings for audited duration clearing, current-state-safe host launch, unresolved provider generations, exact offset-aware slot submission/DST overlap, centralized provider lease fencing, immutable historical migration values, and explicit Zoom Basic/licensed host capability. Round 2 independent re-review accepted all listed areas except N-03, which required this Round 3 remediation.
- Focused local evidence for this round: B2B plus scheduling-domain coverage `72` tests / `344` assertions; Scheduling/M5/M8 regression coverage `117` / `619` plus SurveyDefinitionBuilder `8` / `119` under the established 512 MiB PHP limit; M11A/M11B/M11C, Portal, Telegram, and Finance coverage `143` / `978`. Changed-file PHP syntax, Pint, scoped Larastan (`errors=0`), ESLint, `vue-tsc`, Vite build, and `git diff --check` pass.
- PostgreSQL-specific migration, scheduling-race, and provider-lease coverage exists in `tests/Integration/B2bSalesCallPostgresTest.php`, but the local configured driver is SQLite; its `5` tests are `NOT RUN LOCALLY`/skipped and no PostgreSQL pass is claimed. Hosted CI, staging, deployment, merge, fresh independent re-review, and owner/manual acceptance remain `NOT DONE`.
- OQ-007 remains OPEN, OQ-015 remains unresolved, M12 remains `NOT_STARTED`, and PR #23 remains untouched. No tracking fields, CRM timing benchmark, unrelated product work, or Phase 2 behavior was added.

## 2026-08-27 — M11D B2B lead funnel / Zoom sales handoff — remediation candidate

- Remediation starts at reviewed candidate SHA `7cb7e15ba3ed4e5136f58943ad7305ce6fdf4565` on `codex/m11d-b2b-lead-funnel`; accepted M11C remains preserved at main SHA `978eca9b86cb88f9a5b02b66cc28fe2d8b60026f`. M11 stays `IN_PROGRESS`.
- The independent review of the starting M11D candidate is `BLOCKED`; this bounded remediation addresses its provider reconciliation/deadline fencing, explicit B2B duration configuration, bounded client availability, stale Scenario delivery, PostgreSQL constraint preservation, scheduling race classification, typed specialist segmentation, and host URL validation findings.
- `REQ-B2B-001` is a `CANDIDATE`, not `IMPLEMENTED` or `ACCEPTED`: it contains the Phase 1 B2B acquisition vertical slice with explicit self-declared massage/bodywork specialist segmentation, the managed `🚀 Хочешь себе такого бота? / Развить бизнес` entry, one shared Telegram/Portal lead flow, durable sales-call requests, and bounded CRM handling. The requirement explicitly carries no Phase 2 white-label/SaaS implication.
- B2B SalesCall is separate from Booking and Finance. Its typed linked `UnavailablePeriod` projection participates in the existing Specialist locking and availability authority, so Booking, B2B calls, and unavailability conflict without a parallel calendar and cancellation/rescheduling keeps the projection consistent.
- Zoom uses the provider-neutral `VideoMeetingProvider` boundary and a Zoom Server-to-Server OAuth adapter. Local SalesCall state is authoritative; provider create/update/cancel/reconcile operations are durable, post-commit, retryable, and separately visible from operational call state. Host launch URLs are obtained only through an authorized server-side CRM action.
- Focused local evidence: B2B lead/provider/ready coverage `52` tests / `258` assertions; M5/M8/M11B/M11C and adjacent Scenario coverage `105` / `505`; Portal, Telegram, scheduling, Finance projection, and related unit regressions `150` / `995`; SurveyDefinitionBuilder `8` / `119` under the required 512 MiB PHP limit. The separate unchanged `FinanceCrmUxTest` has `29` passing tests and one SQLite decimal-string representation mismatch (`500` versus the expected padded value). Changed-file PHP syntax, Pint, scoped Larastan (`errors=0`), ESLint, `vue-tsc`, Vite build, and `git diff --check` pass. No CRM timing benchmark was created or run.
- PostgreSQL-specific B2B coverage exists in `tests/Integration/B2bSalesCallPostgresTest.php`, but PostgreSQL is `EXISTS — NOT RUN LOCALLY` when the configured local server is unavailable; no PostgreSQL pass is claimed. Hosted CI, staging, deployment, merge, independent re-review, and owner/manual acceptance remain `NOT DONE` for M11D.
- OQ-007 remains OPEN, OQ-015 remains unresolved, M12 remains `NOT_STARTED`, and no reward economics, medical marketing payload, Phase 2 tenant behavior, or unrelated CRM cleanup was added. PR #23 remains untouched.

## 2026-08-27 — M11C CRM analytics dashboard — ACCEPTED / MERGED

- M11C accepted head `0ce502eb2532a2f022c55baf58fd909fcda79c25` was merged into main at `978eca9b86cb88f9a5b02b66cc28fe2d8b60026f`. M11A and M11B are also merged and preserved in that starting revision.
- The existing Filament `Инфопанель` retains `UpcomingBookingsWidget` and the accepted read-only organization-scoped analytics projections, shared organization-timezone period filter, least-privilege widgets, source-record reconciliation, and historical/operational metric definitions.
- `REQ-CRM-002` is accepted and merged. Its analytics source-of-truth and PostgreSQL verification history remain recorded in the M11C implementation evidence.

## 2026-08-26 — M11B stacked remediation candidate (historical state)

- `REQ-BROADCAST-001`–`REQ-BROADCAST-003` are implemented as an in-review remediation candidate with pre-I/O delivery attempts, terminal unknown outcomes for Telegram acknowledgement ambiguity, no blind replay, exact draft-revision-bound snapshots, active-template/consent/verified-target/creator-authority revalidation, bounded durable dispatch recovery, and organization-safe paginated recipient history. M11A PR #22 remains unmodified and unmerged; M11 remains `IN_PROGRESS`, not accepted or closed.
- Typed segmentation covers tags, B2B role, generic survey completion as a non-clinical engagement fact, booking status/completed-visit count/last visit/no future rebooking, M11A referral/source, language, and verified channel availability. Encrypted survey result categories and every clinical/medical/free-text filter fail closed; no OQ-015 content or sensitive-health marketing authority is fabricated.
- Marketing templates are Broadcast-only for current scope. Scenario rules remain service/transactional-only, Broadcast variables are limited to `client.full_name` and `client.language`, and organization-timezone scheduling uses authoritative IANA instants. No provider-backed Telegram idempotency or exactly-once external-delivery guarantee is claimed.
- Focused local evidence: M11B Feature `32` tests / `102` assertions, M11B Unit segment `9` / `10`, and migration identifier/symmetry `1` / `118` (combined `42` / `231`); the latest M5/M11A regression batch `82` / `514`; Pint, changed-file PHP syntax, Larastan (`errors=0`), ESLint, `vue-tsc`, Vite build, Composer audit, npm audit, and `git diff --check` pass. PostgreSQL/concurrency suites contain `16` M11B tests and `12` M11A tests, but are `EXISTS — NOT RUN LOCALLY` because the local SQLite-only environment has no PostgreSQL/Docker service; no PostgreSQL pass is claimed. SQLite migration up/one-step down round-trip passes for the broadcast migration. `make quality` reaches the full Unit pass (`108` / `568`) but the Feature subprocess hits the repository's local PHP `128 MiB` limit in `MedicalAttachmentTest::test_default_runtime_scanner_fails_closed_and_quarantines_uploads`; hosted CI was not dispatched.
- `REQ-NOTIFY-008` remains FUTURE: no preference center, per-channel preferences, quiet hours, frequency caps, or new unsubscribe/legal semantics were added. OQ-007 remains OPEN; no rewards, M11C analytics, M12 subscription, deployment, or merge work is included.

## 2026-08-25 — M11A final narrow remediation candidate

- Stage 10.2 is `DONE / CLOSED / ACCEPTED` at main SHA `6df5ac5b2c95adf6faf7a69a3b551d2c0f1cb713`; PR #21 was squash-merged after final hosted CI GREEN. The earlier Stage 10.2 remediation entries below are retained as historical evidence and do not describe this milestone.
- M11 is `IN_PROGRESS`. The active M11A remediation branch is `codex/m11a-acquisition-referrals-feedback`, beginning with requested SHA `251ee18a942cf0c374f7acf4ebfab7ebec118b99`; PR #22 currently carries the remediation candidate. The exact accepted candidate SHA will be recorded after hosted acceptance/merge. This batch is limited to Horizon referral queue consumption, durable retry/backoff authority, fenced terminal state, PostgreSQL event-to-obligation provenance, and real acquisition/referral concurrency and crash/retry coverage.
- OQ-007 remains OPEN for product/program conversion qualification and all reward economics/lifecycle rules: amount, percentage, points, currency, earning eligibility, redemption, expiry, refund reversal, and cash-out. No qualification or reward behavior is implemented, and no 9-systems/MSQ content is fabricated.
- M11A remains product-neutral: no Broadcast Engine, segmentation, analytics, reward controls, subscriptions, courses, or bot commerce. PostgreSQL database and concurrency tests remain wired into the existing `test-integration-foundation` and `test-integration-concurrency` targets.
- Focused local PHPUnit passed 98 Unit/Feature tests / 694 assertions. Twelve PostgreSQL-only integration/concurrency tests were skipped because PHPUnit is configured for SQLite and local PostgreSQL/Docker was unavailable; these skips are not PostgreSQL proof. Pint, PHP syntax, changed-production Larastan (`errors=0`), and `git diff --check` passed. Hosted CI, deployment, merge, and independent acceptance are not claimed. PR #23 remains untouched.

## 2026-08-24 — Stage 10.2 Knowledge Filament lifecycle remediation candidate

- Starts exactly at `5cfb300baf501f25459baa44d88a49933d011ace`. The hosted-green PostgreSQL ambiguity remediation remains intact; staging then exposed a separate Filament 5.7.6 lazy RelationManager lifecycle failure. `RevisionsRelationManager` now opts out of lazy loading through Filament's supported API, with no Companion application changes.
- Full relation-manager and parent Edit page render coverage now exercises the table HTML, revision fields, ingestion presentation, and download/retry/pending-start/search-reprocess visibility. Local SQLite Feature coverage passes; the PostgreSQL regression remains present but is `EXISTS — NOT RUN LOCALLY` because PHPUnit forces SQLite.
- This is still a remediation candidate pending fresh independent review, exact-SHA hosted PostgreSQL/staging verification, and final CI. No deployment, hosted CI, merge, M11, or OQ-015 content was added. M8 remains blocked on OQ-015.

## 2026-08-24 — Stage 10.2 Knowledge Filament PostgreSQL remediation candidate

- Starts exactly at `4de4a198f9e0dc4e4bef398d50f27a99727d3dfc` and fixes the staging-found PostgreSQL `42702 ambiguous_column` in Knowledge source/revision Filament projections without changing Companion state machines.
- The real Filament regression test is added under `tests/Integration/KnowledgeRevisionsFilamentPostgresTest.php`; its PostgreSQL execution, fresh independent review, exact-SHA staging redeploy, hosted CI, and merge remain pending. Local PostgreSQL was unavailable; the SQLite execution was supplementary only.
- Stage 10.2 remains an implementation-remediation candidate. M11 is not started and OQ-015 content is not fabricated.

## 2026-08-24 — Stage 10.2 AI Companion — SECOND REMEDIATION CANDIDATE

- Candidate branch: `codex/ai-companion`; this remediation starts exactly at `468fdb82a6eb4073393d3d0952651bc6d6571a4f`, based on accepted Stage 10.2 base `4214ed39918f5dc1da844d7f88e5a06fc20c1b57`. Previous implementation candidate `b6f885e6e0156ce4bab767a40484a6d19edc01b2` remains historical context only.
- Processing leases now snapshot the authoritative M10 whole-run deadline and retain authority through that deadline plus bounded grace. AiRun receives the same snapshot; terminal writes require the active fenced owner and deadline, while reset, handoff, cancellation, expired leases, and deadline expiry prevent stale publication.
- Telegram text bursts and media groups use separate bounded quiet policies. Album turns persist a maximum assembly deadline, seal only from locked fresh state, preserve all received items, and invalidate an incomplete result at most once when a same-group item arrives after sealing. Existing image limits remain fail-closed.
- Legacy adoption and live binding resolution now use one PostgreSQL ownership/binding/conversation lock hierarchy with deterministic conversation ordering and bounded uniqueness retries. Existing encrypted move semantics and ambiguity fail-closed behavior remain intact.
- Practitioner history explains uncertain Telegram delivery as `Доставка в Telegram не подтверждена` with the non-replay explanation; technical metadata remains advanced/permission-gated. Ordinary Portal history no longer returns the unused `conversation: null` field.
- Focused local evidence currently executed: Client Companion feature group `64` tests / `305` assertions; AI plus Knowledge/RAG feature groups `324` tests / `1,836` assertions; M2/Portal/Telegram/privacy/security regression batch `44` tests / `280` assertions. PostgreSQL Client Companion concurrency file contains `11` tests, but all `11` were skipped by the repository's SQLite PHPUnit configuration; Docker is unavailable and no local PostgreSQL service is installed, so no PostgreSQL pass is claimed. Hosted CI, staging, deployment, merge, and independent acceptance remain unperformed. M11 is not started; OQ-015 content is not fabricated.

## 2026-08-23 — Stage 10.1 AI Evaluation Quality / Observability — CLOSED / ACCEPTED

- Candidate branch: `codex/ai-eval-quality-observability`, based exactly on `44a4ba09f01803a740b299786fabc6ad2a0ad49a`.
- Extends the accepted M10 evaluation foundation with a bounded server-owned assertion registry for required/forbidden text, output presence, JSON schema, required fields, bounded values, and RAG source provenance. Unknown assertion types fail closed.
- Evaluation runs now retain immutable suite/case/assertion/schema/prompt/model/capability snapshots and safe case-level typed results. Run metrics distinguish answer quality from execution errors and aggregate pass rate, RAG checks, token usage, Chuklov estimated cost, provider-reported cost, latency, retry/failover, and human-review decisions without decrypting medical payloads.
- Filament `Искусственный интеллект → Проверки AI` adds Russian quality history, safe failure detail, and compatible-run comparison. Protected AI traces remain behind the existing authorization boundary. The optional judge layer is explicitly disabled and produces no scores unless a later configured extension is added.
- Stage 10.1 is closed before Stage 10.2. M11 is not advanced. M8 remains blocked by OQ-015. No provider catalog redesign, real-provider CI evaluation, deployment, or merge is part of this remediation.


## UX-A — Client Workspace + ordinary client/global search — IMPLEMENTATION CANDIDATE — 2026-08-18

- Candidate branch: `ux-a-client-workspace-search`, based exactly on `90ceb3c5da1dd970315f2d82512e1cf6a11119e9`.
- Implemented the bounded identity-only search path, canonical phone key/index/backfill, and composed Client Clinical Cockpit without adding an `app/Modules/CRM` business layer.
- Staging acceptance feedback produced a focused follow-up: the Surveys relation now uses a Filament-compatible Eloquent relation with bounded authorized Application constraints; cockpit actions, tabs, profile text, and client tables are responsive; attachment scan/quarantine state is explicit to the user without weakening fail-closed private storage.
- Focused PHPUnit, Larastan, Pint, PHP syntax, `git diff --check`, and Playwright `--list` checks pass locally. PostgreSQL integration, hosted CI, and full browser execution have not been run under the requested local-light policy.
- UX-A remains an implementation candidate pending independent acceptance. UX-A2 medical discovery, UX-B media/theme work, UX-C cleanup, and M11 remain incomplete and unchanged.

## 2026-08-18 — M10 AI Components & Control Plane — CLOSED / ACCEPTED

- Accepted main SHA: `a0746bad2d4e0695d80ff8689c49b1c57a24161e`.
- PR #8 was squash-merged from accepted head `6def6a6c4c2fb591401a10feaea2b3a11f92c549`.
- The squash main tree is identical to the accepted head tree.
- Final hosted evidence: Quality run `32112766821` — GREEN; Integration concurrency run `32112766462` — GREEN; 22 tests / 116 assertions.
- Previously obtained unaffected Foundation/RAG/privacy/Docker evidence remained valid through final remediation under the repository's semantic evidence reuse policy.
- M10 independent adversarial blockers and follow-up remediation are closed.
- Status: M10 is `CLOSED / ACCEPTED`. M0–M7 and M9 are CLOSED / ACCEPTED. M8 remains content-blocked by OQ-015.

## 2026-08-17 - M9 controlled organization knowledge retrieval - CLOSED / ACCEPTED

- Implemented the organization-only Knowledge module for `REQ-RAG-001` through `REQ-RAG-004`: authored Markdown/plain text and private TXT/Markdown uploads, immutable revisions, durable ingestion runs, deterministic normalized-character chunking, provider-neutral embeddings, pgvector retrieval through `KnowledgeRetriever`, and stable source/revision/chunk/run provenance. OQ-008 remains open, so no platform-shared method library exists; medical/client records, Sessions, attachments, conversations, and survey answers are not automatically ingested.
- Every source, revision, run, chunk, job, query, filter, and result is Organization-scoped. Composite PostgreSQL foreign keys and unique run/chunk identities back the Application boundary; source filters are revalidated against server-derived `OrganizationContext`. Retrieval applies tenant/availability/configuration predicates before exact cosine ordering and fails closed on incompatible embedding provenance.
- Ingestion jobs carry identifiers only. PostgreSQL row locks, configuration identity, processing-lease recovery, partial-chunk cleanup, deterministic upsert, and an atomic ready/active transition cover duplicate requests, retries, competing workers, late old revisions, and re-embedding. Partial/failed/retired/stale content is not retrievable.
- Added explicit `ViewKnowledge`/`ManageKnowledge` permissions, allowlisted structural audit metadata, private upload validation and size bounds, sanitized provider failure messages, Filament source/version/status management, retirement/reactivation, and a bounded retrieval inspection page without raw embeddings. The CRM sidebar is grouped by business task, with knowledge management and inspection together under `Контент и знания`. Retrieved commands, URLs, code, templates, and prompt-like instructions remain inert data; M9 builds no final AI answer.
- Deterministic local evidence: focused PHPUnit passes 16 tests / 87 assertions; narrow production Larastan passes with 0 errors; Pint and `git diff --check` pass; Composer audit reports no advisories; npm audit reports 0 vulnerabilities; the scoped secret-pattern scan is clean.
- Status: M9 is CLOSED / ACCEPTED.

## 2026-08-16 - M8 Surveys / Road Map implementation candidate - CONTENT BLOCKED

- Implemented organization-scoped draft/published/retired survey definitions, immutable published versions, exact-version attempts, encrypted definition/answer/scoring/result/report/comparison snapshots, deterministic typed conditions/scoring/thresholds/tags, non-AI reports, and compatibility-keyed repeat comparison.
- Completion materializes one report and one idempotent `survey.completed` Scenario event in one transaction. Compatible configured repeat metrics can create one idempotent `TEST_STAGNATION_DETECTED`; incompatible versions remain explicitly non-comparable. Recipients, channels, delays, templates, and tone remain Scenario configuration.
- Added structured Filament management and bounded attempt history plus responsive Inertia Portal list/start/resume/save/complete/report flows. Reports expose configured metrics and threshold text only, with no diagnosis, treatment recommendation, or AI output.
- Added `surveys:import` for validated definition JSON. The approved local v2.2 source names “9 systems” and MSQ but says their full questionnaires were moved to separate documents that are absent locally. No questions, scores, thresholds, or interpretation were fabricated. See OQ-015.
- Focused local evidence so far: M8 PHPUnit passes 9 tests / 60 assertions; the affected M6 Scenario regression file also passes 15 tests / 122 assertions; narrow Larastan passes with 0 errors using a 512 MB local limit; Pint, ESLint, `vue-tsc`, and Vite build pass; `git diff --check` passes. PostgreSQL composite-FK integration coverage was added but not run locally; privacy, Docker/runtime, full quality, PostgreSQL integration, and hosted candidate CI are not yet claimed.
- Status: M8 is `BLOCKED` on source-backed 9-systems and MSQ content and remains unaccepted pending candidate CI. M9 is CLOSED / ACCEPTED; M10 is CLOSED / ACCEPTED; M8 contains no LLM or generative AI implementation.

## 2026-08-16 - Candidate-only hosted CI policy

- Heavy `CI` is manual `workflow_dispatch` only; automatic `push` and `pull_request` triggers are removed without changing its quality/integration, privacy/secret, or Docker/runtime jobs.
- Agents use focused local feedback while assembling a coherent candidate, then manually dispatch one hosted candidate gate. Related remediation is batched before another run; high-risk tenant/security/encryption/migration/concurrency changes may justify an additional candidate.
- Scheduled/manual Playwright remains a separate non-blocking workflow. Local Docker, Playwright, heavy integration, and `make ci` remain prohibited unless the owner explicitly authorizes them.

- Last updated: 2026-08-28
- Current phase: Phase 1 foundation
- Current milestone: M11D — B2B lead funnel / Zoom sales handoff (implementation candidate)
- Status: M0–M7, M9–M10, Stage 10.1, and Stage 10.2 are CLOSED / ACCEPTED. M11 is IN_PROGRESS; M11A, M11B, and M11C are merged and preserved, while M11D remains pending independent re-review, PostgreSQL verification, hosted gates, staging/manual acceptance, and owner acceptance. M8 remains BLOCKED by OQ-015, OQ-007 remains OPEN, and M12 remains NOT_STARTED.

## Milestone 7 Final Slice — Session Files + Longitudinal Dynamics — CLOSED / ACCEPTED — 2026-08-16

- Added `medical_session_attachments`, an explicit organization/client-owned association between existing `medical_sessions` and `medical_attachments`. Composite foreign keys require both sides to share the same Organization and Client; linking copies no bytes, and unlinking deletes only the association.
- Added Application actions for bounded same-client attachment search, link/unlink, linked metadata projection, and current-versus-immediately-previous Session dynamics. Attachment management reuses existing authorization, scan/quarantine, private storage, and temporary signed-download boundaries. Only cleared linked files receive a download URL.
- Session detail now presents structural facts, the six specialist-confirmed clinical fields for current and immediately previous Sessions, linked file metadata, authorized downloads, link/unlink actions, Edit, and return to Session history. Dynamics adds no score, diagnosis, automated judgement, or AI and decrypts only the two explicitly displayed Sessions.
- Focused local verification: Session attachment, cockpit, and Medical Session PHPUnit suites pass (59 tests, 255 assertions); narrow Larastan on changed application paths, focused E2E ESLint, Pint, and `git diff --check` pass. PostgreSQL integration, desktop/mobile Playwright, privacy, Docker/runtime, and full quality remain hosted-CI-only under repository policy.
- Status: M7 is CLOSED / ACCEPTED at `e003a7056ce14c4ef55ea69b2e48c866f3156c36` after exact-SHA post-merge hosted CI run `31957913038` succeeded. The scheduled/manual Playwright workflow is non-blocking and is not claimed as closeout evidence. M8–M10 and real AI remain NOT STARTED.

## Milestone 7B.1 — Durable Medical Session Foundation — IMPLEMENTATION CANDIDATE — 2026-08-16

- Implemented the durable backend foundation for specialist-confirmed medical Sessions only. Built the `medical_sessions` PostgreSQL table with composite-tenant identity `(organization_id, id)` and composite foreign keys to `clients` (NOT NULL), `specialists` (NOT NULL), and an optional nullable composite FK to `bookings`. Added a justified index `(organization_id, client_id, occurred_at, id)` for future chronological client history. No partitioning, no status column, no speculative specialist/booking indexes.
- Reused the existing dedicated medical encryption boundary (`MedicalEncryptorInterface` / `MedicalKeyResolverInterface` from M7A) via the generic `encryptField` / `decryptField` primitives for the six Class C Session clinical fields (`pain`, `tests`, `observations`, `root_cause_hypothesis`, `protocol`, `result`); `encryption_key_version` per row; no Laravel encrypted casts; ciphertext only at rest; no plaintext in audit metadata or logs. `medical_sessions` does NOT add an `anamnesis` column — longitudinal anamnesis remains owned by `medical_profiles`.
- Added `app/Modules/Sessions/{Application,Domain}` with `MedicalSession` model, `EncryptedSessionPayload` VO, `MedicalSessionAuthorization`, `CreateSession`, `GetSession`, `UpdateSession` actions, and `MedicalSessionData` / `CreateSessionCommand` / `UpdateSessionCommand` DTOs. No `ListSessions`, no delete, no Filament UI, no AI, no M8+ surveys, no attachment linking.
- Application authorization reuses `OrganizationPermission::ViewClients` / `ManageClients` via `OrganizationContext` / `OrganizationAuthorizer`; structural ownership is additionally enforced by PostgreSQL composite foreign keys. Application actions re-validate that an optional Booking belongs to the session's organization, client, and specialist. Specialist activity is NOT required, allowing safe retroactive/historical recording.
- Registered `medical.session.created` and `medical.session.updated` audit actions with allowlisted metadata only (`source`, `key_version`, `booking_id`, `client_id`, `specialist_id` for create; `source`, `key_version`, `updated_fields` for update serialized as `implode(',', $names)`; no array metadata values; no clinical plaintext). Extended log redaction with a dedicated narrow Session-clinical matcher: exact context-key match for `pain`/`tests`/`observations`/`root_cause_hypothesis`/`protocol`/`result`, plus a defense-in-depth message-text matcher that redacts ONLY explicitly namespaced clinical labels (`medical_session.<field>=...`, `medical_session.<field>: ...`, `clinical.<field>=...`, `clinical.<field>: ...`). Bare operational labels (`Result:`, `test:`, `tests=`, `protocol:`, etc.) are deliberately NOT classified; the general sensitive-key pattern was not broadened with `test`/`result`/`session`/`protocol`/`clinical` operational terms.
- UpdateSession uses full clinical snapshot semantics: `UpdateSessionCommand::fromArray()` requires all six clinical field keys explicitly (each value may itself be null to clear that field); missing keys are rejected as validation errors. UpdateSession persists the full supplied snapshot and records `updated_fields` from actual on-disk decrypted value differences (including explicit clears). `CreateSessionCommand::fromArray()` rejects missing/empty `occurred_at` rather than defaulting to current time; the authorized `Client` argument remains the sole client authority (`clientId` removed from `CreateSessionCommand`). `occurred_at` and structural ids remain immutable in `UpdateSession`.
- Local verification (does NOT include PostgreSQL integration tests): focused PHPUnit feature tests under `tests/Feature/Sessions/MedicalSessionTest.php` pass locally under sqlite; M7A MedicalProfile regression and M1 security redaction regression pass locally; Larastan clean on changed `app/` paths; Pint clean on changed files. PostgreSQL composite-FK rejection integration tests under `tests/Integration/MilestoneSevenDatabaseTest.php` were added and are executed only by hosted CI; they were NOT run locally.
- Status: M7 remains IN_PROGRESS; M7B.2 Session Cockpit UI / client history is an implementation candidate, while M7B and REQ-MEDICAL-001 remain incomplete; OQ-013 remains OPEN. M7A status is unchanged. Hosted CI must pass on the exact candidate SHA before any acceptance.

## Milestone 7B.2 — CRM Session Cockpit UI + Client-Scoped Session History — IMPLEMENTATION / VERIFICATION CANDIDATE — 2026-08-16

- Added the CRM Client → Sessions history, create, detail, and edit flow using the existing `CreateSession`, `GetSession`, and `UpdateSession` application boundaries. History is fixed-client and organization scoped, structurally projected, SQL paginated at 25 or 50 rows, ordered by `occurred_at DESC, id DESC`, and eager-loads only specialist and booking display metadata.
- Added bounded organization-safe Specialist search and bounded organization/client/specialist-safe optional Booking search. Invalid or tampered identifiers are rejected as form validation errors; Booking lifecycle is unchanged.
- Focused local verification: Session cockpit and Medical Session PHPUnit tests pass (49 tests, 221 assertions); narrow Larastan on changed application paths passes; Pint and `git diff --check` pass; the focused Playwright flow is listed for desktop and mobile but browser execution awaits hosted CI. M7 remains IN_PROGRESS; M7B and `REQ-MEDICAL-001` remain incomplete; hosted CI is not yet verification for this candidate.

## CRM Performance & Staging Runtime Remediation — DEPLOYED / HOSTED CI GREEN — 2026-08-15

- Remediated staging and CRM performance bottleneck: enabled Filament SPA navigation (`->spa()`) with binary attachment/receipt URL exceptions; transitioned container runtime from single-threaded PHP CLI built-in web server to production-grade `php:8.5.9-fpm-bookworm` + Nginx with clean PHP 8.5 OPcache and PID 1 fail-fast supervisor; added repository-tracked staging host Nginx reverse proxy template serving static fingerprinted Vite assets (`/build/*`), fonts, brand, and published vendor styles with long-lived immutable cache headers.
- Eliminated redundant O(N) repeated queries and duplicate reads: request-scoped memoization in `GetMedicalProfile` (keyed by `actor_id:org_id:client_id`) with strict per-read authorization; direct organization context resolution in `ClientPolicy` and `BookingPolicy`; static in-memory memoization in `OrganizationFeatureGate` and `GetBookingLeadTime` with context-scoped invalidation.
- Deployment fixes applied: (1) PHP-FPM global directives were placed after the pool include, triggering `unknown entry 'error_log'` — now ordered correctly; (2) legacy staging workers ran as root under `cap_drop: ALL` against hardened `www-data`-owned runtime directories — app/workers now run as UID/GID `33:33`. The deploy path now guards these runtime assumptions so equivalent legacy drift is detected before release switching.
- Deployed application SHA: `594a38b7dbad16576308ab1965feffb5d88849b0`.
- Hosted CI run `31904011750` green: Quality and integration, Playwright desktop and mobile, Privacy and secret scan, Docker build and runtime health.
- Staging verified at the exact deployed SHA: `/health` healthy; PHP-FPM, container Nginx, OPcache, Horizon, scheduler, and Telegram running; PostgreSQL/pgvector/Redis healthy; private storage protected; `php -S` absent; database not reset; unrelated host services unchanged.
- Automated authenticated AFTER page-timing benchmarks were not completed; perceived CRM/UI speed is evaluated manually by the owner, and no scripted CRM timing benchmark is required unless the owner explicitly requests one.

## Milestone 7A — IMPLEMENTATION CANDIDATE — 2026-08-15

- Implemented M7A scope (`REQ-CLIENT-002`, `REQ-MEDICAL-SEC-001`, `REQ-ATTACHMENT-001`, `REQ-ATTACHMENT-002`, ADR-017). M7B.2 is now an implementation candidate; M7B overall and M8+ remain incomplete.
- ADR-017 accepted: Class C clinical fields (`anamnesis`, `complaints_goals`, `operations_injuries`, `medicines`, `supplements`) are encrypted at rest with AES-256-CBC and HMAC-SHA256 authenticated envelopes using `Illuminate\Encryption\Encrypter` behind `MedicalEncryptorInterface` and `MedicalKeyResolverInterface`. Database models store raw ciphertext with an explicit `encryption_key_version` and rotation seam.
- Dedicated medical encryption secret configured outside the database via medical configuration (`config/medical.php`), decoupling medical encryption from application key rotation.
- Forward-only additive PostgreSQL migrations created: `2026_08_15_140000_create_medical_profiles_table.php` (composite foreign key `(organization_id, client_id)` referencing `clients(organization_id, id)` and unique `[organization_id, client_id]`) and `2026_08_15_140001_create_medical_attachments_table.php` (not-null client ownership and composite foreign keys to `clients` and `organization_memberships` with restrict-on-delete semantics).
- Application actions `GetMedicalProfile` and `UpdateMedicalProfile` enforce server-derived organization context and authorization (`OrganizationPermission::ViewClients`/`ManageClients`) and length limits (<= 10,000 chars per field).
- Private attachment storage on disk `private` under UUID-based paths `medical/attachments/{organization_id}/{uuid}.{ext}` via `AttachmentStorageInterface` / `PrivateMedicalAttachmentStorage`. Uploads enforce server-side MIME sniffing, explicit raw DICOM rejection (detecting `.dcm`/`.dicom`, MIME `application/dicom`, and 128-byte preamble with `DICM` signature), configurable file size limits (default 20 MB recorded in ASM-008), and SHA-256 checksums.
- Attachment taxonomy strictly restricted to confirmed requirements: `medical_report` and `posture_photo`.
- Fail-closed runtime scanner `FailClosedAttachmentScanner` binds by default in normal runtime to quarantine unconfigured uploads. Test fake `LocalDeterministicAttachmentScanner` is retained solely for testing.
- Temporary signed download URL generation (`GetTemporaryAttachmentUrl`, 15-minute default TTL) and streaming controller `AdminMedicalAttachmentController` (`GET /admin/attachments/{uuid}`) requiring valid signatures, staff authorization, and cleared status without exposing storage paths.
- Filament CRM integration: `ClientResource` infolist displays decrypted fields and temporary signed download links; dedicated `editMedicalProfile` modal action handles medical updates cleanly without touching ordinary client profile saves.
- Security allowlists updated in `RecordAuditEvent` (`medical.profile.created`, `medical.profile.updated`, `attachment.uploaded`, `attachment.downloaded`) with zero plaintext clinical data; Monolog processor `RedactSensitiveLogData` masks medical field names; scan metadata is sanitized to allowlisted keys.
- Local verification: 34 Unit tests (61 assertions) and 252 Feature tests (1549 assertions) pass; 61 PostgreSQL integration tests (151 assertions) pass; 24 Playwright e2e tests pass; Pint, ESLint, Larastan (0 errors), vue-tsc (0 errors), Vite build, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. Local `make quality`, `make privacy`, and `make ci` pass.
- Hosted exact-SHA CI run `31895042962` is green for `48c12884ff86adc9c33691ba05964b0ec87935e1`: Quality/integration `95036954471`, Privacy/secret scan `95036954456`, Docker/runtime health `95036954441`, Playwright desktop/mobile `95036954586`.
- Guarded staging now runs exact application SHA `48c12884ff86adc9c33691ba05964b0ec87935e1` at `https://crm.psysoldatov.ru`; forward migrations applied without database reset; dedicated medical encryption configuration verified; staging smoke verified encryption at rest, cross-org isolation, fail-closed runtime scanner quarantine, quarantined download blocking, and temporary signed URLs.
- Status: M7 remains IN_PROGRESS; M7B.2 is an implementation candidate; M7B overall remains incomplete; OQ-013 remains OPEN.

## Milestone 6 — CLOSED / ACCEPTED — 2026-08-15

- Remediation started from pre-remediation M6 application SHA `b0bacaf4130f192f95dea07744f8560ebaf4b611`; the final remediation application SHA is `72b4987124c4897bb6fe34bd74443848f6f85eea`. M5B application behavior remains unchanged.
- Added forward-only organization-scoped finance bootstrap `2026_08_15_100002_bootstrap_finance_currency_configuration`: a single existing priced Service currency becomes an explicit force-single configuration, no-priced organizations remain unconfigured for owner setup, multiple priced currencies fail before writes, and existing owner configuration is never overwritten. Configuration saves now validate existing Service/obligation currencies and all required directed FX paths atomically; same-currency paths require no rate.
- Open-balance reconciliation now sums settlement entries in settlement currency and values remaining base/display debt with the immutable obligation conversion snapshot. Payment-time rate snapshots remain historical ledger evidence; later rates cannot create a negative display/base balance. Invalid valuation snapshots fail visibly, and Portal/Scenario/CRM projections do not clamp impossible values.
- Focused M6 Feature coverage passes 15 tests/122 assertions; PostgreSQL Integration coverage passes 55 tests/145 assertions; M5B regression coverage passes 11 tests/42 assertions; relevant CRM/Portal Playwright coverage passes 22/22 desktop/mobile. Local `make quality`, `make privacy`, and `make ci` pass.
- Hosted exact-SHA CI run `31884758963` is green for `72b4987124c4897bb6fe34bd74443848f6f85eea`: Quality/integration `95012327744`, Privacy/secret scan `95012327712`, Docker/runtime health `95012327769`, and Playwright desktop/mobile `95012327733`.
- Guarded staging now runs exact application SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` at `https://crm.psysoldatov.ru`; the forward-only bootstrap migration applied without a database reset, health reports database/pgvector/Redis/private storage healthy, Horizon is running, scheduler and Telegram containers are running, and the isolated Compose app/Horizon/scheduler/Telegram images all match the remediation SHA. The pre-smoke staging inventory contained one organization, no priced Services, and no Finance configuration rows.
- Rollback-safe staging Finance smoke created a temporary pre-M6-like USD-priced Service and confirmed Booking, ran bootstrap twice, verified the Service remained listed, rejected missing directed FX atomically, preserved an owner-edited configuration on repeat bootstrap, completed the Booking into an obligation, exercised obligation-rate `90` with a later payment-rate `200`, and verified settlement `5000` USD minor units, display/base `450000` RUB minor units, Portal `450000` RUB, CRM `50.00 USD` settlement outstanding, Scenario debt `450000` RUB, and exact zero after full settlement. Temporary rows were rolled back; no real provider, payment, reminder, or delivery was used.
- Independent remediation re-review returned GO FOR M6 CLOSEOUT. M6 is CLOSED / ACCEPTED: the rollout/currency configuration and mixed-FX open-balance blockers are CLOSED; accepted application SHA is `72b4987124c4897bb6fe34bd74443848f6f85eea`, exact hosted CI run is `31884758963`, and staging runs the same SHA.
- `REQ-CURRENCY-001`–`REQ-CURRENCY-003` and `REQ-PAYMENT-001`–`REQ-PAYMENT-005` are accepted for M6. `REQ-PAYMENT-006` and OQ-005 remain future/outside M6 for M13; OQ-014 remains OPEN; M7+ are NOT STARTED.

## Milestone 5 — CLOSED / ACCEPTED — 2026-08-15

- Implementation started from repository HEAD `7e8a7f7f87fab3b4ed17d2e4fceec0c5e77f579a`; the accepted deployed baseline remains `a487f5c216b43e687b26d6916e6af5032fbe4429`.
- M5B extends the accepted M5A engine with typed `onboarding.started` events, configurable bounded repeat sequences, post-session +24h/+48h/conditional +72h seed rules, typed onboarding/consent/qualifying-next-booking conditions, configurable retention windows, and same-organization member/role recipient validation with verified-channel revalidation.
- Re-engagement consumes the internal `ClientOnboarding` progress record only; the removed visible Portal wizard, surveys/tests, AI prompts, communication-preference model, and all M6+ behavior remain out of scope.
- Retention counts only future `REQUESTED` or `CONFIRMED` bookings within the configured rule window. Materialization and execution both evaluate the typed condition; action template, condition, recipient, channel, render, sequence, and repeat values remain immutable snapshots.
- OQ-014 records the still-unconfirmed business/clinical meaning of the conditional +72h follow-up. The seed uses only a neutral typed completed-booking guard until the owner decides otherwise.
- Focused M5B Feature coverage passes 11 tests/42 assertions; focused M5A regression coverage passes 54 tests/202 assertions; PostgreSQL integration coverage passes 46 tests/104 assertions; focused scenario Playwright passes 2/2 desktop/mobile. `make quality` passes with 27 Unit tests/49 assertions and 215 Feature tests/1,323 assertions; `make ci` passes with healthy PostgreSQL/Redis and the same 46 PostgreSQL integration tests/104 assertions.
- Hosted exact-SHA CI run `31844393385` is green for `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457`: Quality and integration `94907770561`, Privacy and secret scan `94907770571`, Docker build/runtime health `94907770625`, and Playwright desktop/mobile `94907770772`.
- Guarded staging deployment from the accepted deployed baseline `a487f5c216b43e687b26d6916e6af5032fbe4429` succeeded for exact implementation candidate `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457`. The M5B migration is applied; application health reports database, pgvector, Redis, and private storage healthy; Horizon is running; the scheduler lists `scenarios:run`; app, Horizon, scheduler, and Telegram containers are running; the application-level scenarios queue depth and failed scenario-job count are both zero.
- Staging M5B smoke ran the idempotent notification seeder twice, resulting in two localized `post-session-follow-up` templates, six +24h/+48h/conditional +72h rules, and no actions or client records created by the seed. Authenticated CRM browser smoke confirmed the business-labeled rules page, seeded delays/triggers, rule detail/template projection, and scenario-history empty state with zero browser errors. The temporary owner-scoped smoke account was removed. No external Telegram delivery was attempted.
- Independent Sol Max acceptance accepted M5B implementation checkpoint `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457` for M5 closeout. OQ-014 remains OPEN; no M5 production behavior was changed during M6 remediation.

## Client Portal booking acceptance remediation — 2026-08-15

- Code remediation started from `996dc6fcb0088d512f7c516eff1e2851c1cae02a`; deployed code revision is `9250376f2d8ae6b87e29286570291c152b803e2e`.
- The supplied designer brand assets were then restored as the only Portal/browser/Apple/Filament assets in `a487f5c216b43e687b26d6916e6af5032fbe4429`, which is deployed to staging.
- Booking and reschedule calendars now request the complete visible month from the server-authoritative availability path. Month navigation reloads that exact range, outside-month cells are distinct/unavailable, and rescheduling does not hide earlier policy-valid future dates in the same month.
- The 320px multi-specialist/multi-format flow now retains full selected service, specialist, and format context at selection, calendar, confirmation, and success stages without ellipsis or horizontal overflow. The focused worst-case Playwright path covers service, specialist, format, calendar/time, confirmation, and success.
- Booking query, creation, availability, reschedule, cancellation, conflict, cutoff, stale-version, and timezone failures use a shared RU/EN client error mapping. Focused coverage proves English responses are English and Russian responses remain Russian.
- A real Telegram bot update no longer processes `/start web_…` twice: the generic Telegram-link handler excludes browser-login tokens. Focused fake-bot coverage asserts a single successful reply; a true authenticated Telegram-client Mini App/session was unavailable, so no real in-client Telegram smoke is claimed.
- The Portal uses the supplied designer Russian and English CHUKLOV circular logos; the browser, Apple touch, and Filament icon use the supplied English designer mark. All legacy and generated brand files are removed and regression-tested as absent.
- Verification: focused Feature tests `15/15` (150 assertions), local `make quality`, local `make ci`, and full Playwright `24/24` passed. Hosted exact-SHA CI run `31837128566` passed quality/integration, Playwright desktop/mobile, Docker runtime health, and privacy/secret scan for `9250376f2d8ae6b87e29286570291c152b803e2e`; the same four hosted checks passed for the designer-asset correction in run `31838329916` at `a487f5c216b43e687b26d6916e6af5032fbe4429`.
- Guarded staging deployment from expected `d24554d01f149c1fd24c2ac7443c75162186a408` succeeded for `9250376f2d8ae6b87e29286570291c152b803e2e`, and a subsequent guarded exact-SHA deployment from that revision succeeded for `a487f5c216b43e687b26d6916e6af5032fbe4429`; each includes a validated staging database backup, health, Horizon, scheduler, Telegram runtime, and protected-host baseline checks. Public staging browser smoke at desktop/390px/360px/320px confirms the supplied logo/icon assets load, no horizontal overflow, and no page errors. Authenticated staging booking flows were not synthesized; their evidence is local and hosted Playwright.
- Scope remained the three Portal NO-GO blockers plus the owner-reported Telegram duplicate reply and requested favicon/brand correction. M5B and unrelated milestones remain unstarted.

## Client Portal booking visual remediation — 2026-08-14

- Starting code revision: `694d98c9d0392ba50dfd70bc088a5918b18f0a6f`.
- Rebuilt the client booking surface around the approved CHUKLOV reference: focused service rows, meaningful specialist/format decisions, calendar plus selected-day time choices, and confirmation summary. One valid specialist is auto-selected; booking availability remains server-authoritative.
- Removed client-facing date-range controls and multi-day slot dumps. The mobile booking flow hides the persistent bottom navigation while the focused wizard is active so the calendar and CTA remain fully readable; normal Portal destinations retain bottom navigation.
- Follow-up after staging review: a successful booking now renders a dedicated result card instead of leaving the calendar and availability slots underneath the success message; the result retains service, specialist, date/time, and format details, and calendar/time headings have explicit spacing.
- Follow-up after staging review: the persistent Portal shell now uses the full committed `chuklov-logo.jpg` asset with its inscription instead of the cropped mark-only asset; regenerated browser/Apple/Filament PNG icons use the same full-mark source, and the generic profile description was removed.
- The branding baseline was deployed at `9a9e6c9cc3218045178c5b09c4bfd0f5b8fcfe2a`; the booking-details/reschedule remediation is deployed at `d24554d01f149c1fd24c2ac7443c75162186a408` on `https://crm.psysoldatov.ru`.
- Booking details now load without availability, payment, or timezone implementation panels. The home card has no non-interactive reschedule control; rescheduling is an explicit details action with a selected-day calendar/time grid and confirmation. The narrow-shell fix prevents the long service title from expanding a 320px viewport.
- Verification: `make quality`, `make ci`, focused Portal Playwright `16/16`, and real staging smoke at desktop/390px/320px pass. Staging browser checks report no home reschedule label, no initial availability dump, no visible payment/timezone implementation text, seven readable weekday cells, no horizontal overflow at all tested widths, successful reschedule completion, and empty browser/page error collections. Live Telegram launch link resolves to `@chuklovtestbot`; an authenticated Telegram-client session was not available for a true in-client Mini App smoke, so no synthetic initData result is reported as live Telegram verification.
- M5B and unrelated milestones remain unstarted.

## Completed Remediation

- Staging follow-up simplified the Portal entry copy, added ordinary-browser Telegram authentication through a browser-bound single-use bot deep link, retained verified Mini App initData and email OTP, and persisted client-facing copy rules. M5B remains unstarted.
- Staging Telegram follow-up enables the existing `ClientRecords` feature gate only for organization `chuklov`, adds automatic Mini App authentication, and trusts the isolated HTTPS proxy for generated Portal URLs. M5B remains unstarted.
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
- OQ-002 and OQ-009 were resolved during M4B/M4C. OQ-010, OQ-011, and OQ-012 were resolved during M4C; no M4 blocker remains and no unconfirmed payment, refund, or multi-location policy was invented.

## Milestone 3

- Added organization-scoped Filament CRM resources and Application actions for Clients, Service/catalog records, Specialists/Practitioners, and managed localized portal content.
- Client CRM edits preserve verified channel identity semantics and provide audited self-service booking restriction history; no medical fields were added.
- Specialists remain distinct organization-owned identities. Optional staff links use active same-organization `OrganizationMembership` rows and composite database ownership constraints; `users.organization_id` is not used.
- Service catalog configuration supports localized content, category, timing, formats, active state, catalog type, payment policy, and integer minor-unit prices with explicit currency. Products have no commerce workflow.
- No scheduling, booking, payment, medical, notification/scenario, AI, RAG, broadcast, subscription, external-channel, or Phase 2 SaaS behavior was added.

## Milestone 4A

- Accepted M3 base is b15866f007be4a5db8397120d91f4691ce85382b; M3 remains closed and was not re-audited.
- Added organization-scoped recurring specialist working hours, date exceptions, unavailable periods, organization-level booking lead time, typed visit formats, booking persistence, immutable booking events, and the authoritative availability Application path.
- Availability uses specialist-or-organization IANA scheduling timezone, separate client display timezone, DST-safe local wall-clock conversion, service duration/buffer, lead time, exceptions, unavailability, and blocking booking intervals.
- PostgreSQL composite ownership FKs, important checks, GiST exclusion constraints, specialist-row transaction locking, and online-only meeting metadata checks protect scheduling invariants. No application-only check-then-insert strategy is used.
- Added CRM scheduling configuration, exception/unavailable-period resources, and a small authenticated Portal availability projection with explicit Inertia props.
- At M4A close, OQ-002 and OQ-009 were intentionally left open. M4B resolved OQ-009 through explicit assignments. M4C resolves OQ-002, OQ-010, OQ-011, and OQ-012; no M5+ behavior was started.
- Focused verification passes: 16 scheduling Unit/Feature tests/46 assertions and 37 PostgreSQL integration tests/67 assertions. Final make quality and make ci both pass: 13 Unit tests/28 assertions, 99 Feature tests/540 assertions, Larastan 0 errors, clean Pint/ESLint/vue-tsc/Vite, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities. Playwright was not run because M4A adds no interactive booking flow.

## Milestone 4B — Booking lifecycle and CRM/client flow

- Accepted M4A base is af298476aad216b4083b93fc6f6d60f81ca3e52b; M3 remains closed and was not re-audited.
- OQ-009 is resolved as an explicit organization-scoped many-to-many `specialist_service_assignments` relation. CRM assignment actions enforce same-organization ownership, authorization, uniqueness, active-record booking rules, and historical-booking preservation.
- Added non-blocking HOME_VISIT `PENDING_REVIEW`, typed approval/rejection actions, transactionally rechecked approval, immutable booking events, organization-scoped CRM booking list/detail/actions, and the shared client Portal booking journey for OFFICE/ONLINE/HOME_VISIT.
- Focused M4B verification passes: 23 Unit/Feature tests/93 assertions; focused PostgreSQL assignment/pending/race coverage passes with 10 tests/20 assertions. Final `make quality` passes with 13 Unit tests/28 assertions, 106 Feature tests/587 assertions, Pint, Larastan 0 errors, vue-tsc/Vite, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities; the new page's auto-fixable ESLint warnings were corrected before the final CI gate. Final `make ci` passes with healthy PostgreSQL/Redis, clean ESLint, and 40 PostgreSQL integration tests/78 assertions. Final Playwright passes 4/4 on desktop and mobile, including the interactive client booking journey.
- Exact-SHA hosted CI run 31654103577 passes quality/integration, Playwright desktop/mobile, Docker runtime health, and privacy/secret scan for accepted M4B.

## Milestone 4C — final Scheduling / Booking lifecycle

- Accepted M4B base is 2bf8d502b204f982205ab466fa1c4e7c6a4873e5; the separate backlog-normalization documentation commit is ce2110a. M4A/M4B remain accepted and were not re-audited.
- Resolved OQ-002 with an organization-configurable cutoff (Phase 1 default 1440 minutes), reasoned staff override, pending HOME_VISIT withdrawal, stable identity/calendar UID reschedule, and no M4 payment/refund side effects.
- Resolved OQ-010 with reusable future-booking impact calculation, explicit CRM acknowledgement, derived needs-attention visibility, audit evidence, and preservation of existing bookings.
- Resolved OQ-011 with typed terminal NO_SHOW after scheduled start for authorized staff; resolved OQ-012 with one organization-level Phase 1 office and booking-specific HOME_VISIT destination.
- Added PostgreSQL-backed booking idempotency with request-hash replay protection and retention pruning; lifecycle actions/events for cancel/reschedule/confirm/complete/no-show/meeting URL; client My bookings/history/timezone surfaces; CRM management; and HOME_VISIT/ONLINE completion boundaries. No M5+ behavior was started.
- Final local verification passes: focused M4 lifecycle/scheduling coverage 35 tests/163 assertions plus 4 scheduling Unit tests/7 assertions; focused PostgreSQL coverage 10 tests/22 assertions including a process-level competing-booking race; `make quality` 13 Unit tests/28 assertions and 122 Feature tests/664 assertions with clean Pint, Larastan, ESLint, vue-tsc, and Vite; `make ci` 40 PostgreSQL integration tests/78 assertions with healthy PostgreSQL/Redis, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities; Playwright 6/6 desktop/mobile tests.
- Exact-SHA hosted CI run 31691868383 passed for 048c193a37bb2da110a1340093a3d8cb4c443916: quality/integration, Playwright desktop/mobile, Docker build/runtime health, and privacy/secret scan.

## Milestone 4 final-review remediation

- Remediation started from independent-review SHA 4e0b0685e5f62c6973969f1f373b14af79e2490d. M0–M3 were not re-audited and M5+ was not started.
- Fixed M4-01 through M4-14: optimistic event-version reschedule conflicts; required durable booking idempotency and replay-before-mutable checks; CRM booking creation; direct Service Catalog gates; bound schedule-impact projection/acknowledgement including exception deletion; structural needs-attention; safe CRM BookingEvent projection; display-local date filtering; current client-timezone surfaces; locked Specialist impact state; atomic scheduling configuration; and accurate audit labels.
- Adverse focused verification passes: 58 Unit/Feature tests/256 assertions; PostgreSQL 13 tests/34 assertions including real process-level same-Booking reschedule and same-key creation races plus BookingEvent DELETE immutability; DST spring-gap/fall-overlap Unit coverage; CRM and Portal regressions.
- `make quality` passes: 15 Unit tests/32 assertions, 139 Feature tests/746 assertions, clean Pint/ESLint/Larastan/vue-tsc/Vite, Composer audit with 0 advisories, npm audit with 0 vulnerabilities. `make ci` passes with healthy PostgreSQL/Redis and 43 integration tests/90 assertions. Playwright passes 6/6 desktop/mobile.
- Final implementation SHA: 289ec953cd4f4d11b0a5b8cf803dbfdd064d6a7e. Hosted exact-SHA CI run 31701386393 passed privacy/secret scan, quality/integration, Docker runtime health, and Playwright desktop/mobile. `.codex/config.toml` remains unchanged; M5+ remains untouched.

## Important Decisions

- Docker build context is deny-by-default; only reviewed runtime Dockerfiles are allowed.
- Routine setup never rotates a nonblank `APP_KEY`; deliberate rotation is a separate authorized operation.
- M0 hosted CI separates quality/integration, Playwright, privacy/secret, and Docker runtime gates for diagnosability.
- Client source documents and full chat history remain local-only; normalized REQs and sanitized repository docs are the Git development source.
- M1 keeps `organization_id` as the security boundary, derives runtime context from server configuration/membership, and does not introduce `master_id`, heavy tenancy, SaaS provisioning, or provider integrations.
- M1 credentials use Laravel encrypted casts and are only exposed through masked representations; audit metadata is action-allowlisted before persistence.

## Latest Verified Local Quality Gate

2026-08-13: Final M4 narrow remediation evidence includes 6 new Feature tests/55 assertions; `make quality` with 15 Unit tests/32 assertions and 145 Feature tests/801 assertions; `make ci` with 43 PostgreSQL integration tests/91 assertions; and Playwright 6/6 desktop/mobile tests. Hosted exact-SHA CI run `31707507497` passed all jobs for `9730d1fca0137c063deabf853b94e80f54a0936`. Independent Sol final recheck accepted M4 and issued `GO FOR MILESTONE 5`. M0–M3 were not re-audited.

## Milestone 4 final narrow remediation

- Started from accepted remediation SHA `cc5e013a546ff7e6ce0dd424e9b9e4d7e501e507`; scope was limited to M4-06, M4-R01, and M4-R02. M0–M3 were not re-audited and M5+ was not started.
- Added a reusable Filament schedule-impact preview/acknowledgement handoff across scheduling mutations, including quick Specialist deactivation and exception deletion. The digest and safe affected-booking projection are retained server-side; stale acknowledgements, including non-empty-to-empty changes, are rejected.
- Synchronized same-page Portal reschedule/timezone state and rotated booking idempotency keys only after accepted creation. Added transition-aware display-local day boundaries for DST midnight gaps/overlaps, with Cairo, Havana, Santiago, Berlin, and large-offset coverage.
- Narrow remediation verification passes: 6 new Feature tests/55 assertions; `make quality` 15 Unit tests/32 assertions and 145 Feature tests/801 assertions; `make ci` 43 PostgreSQL integration tests/91 assertions; Playwright 6/6 desktop/mobile.

## Milestone 4 closeout

- Milestone 4 is CLOSED and accepted after final remediation and independent Sol recheck. No remaining acceptance blocker or remediation requirement remains.
- Final accepted M4 implementation SHA: `9730d1fca0137c063deabaf853b94e80f54a0936`.
- Hosted exact-SHA CI run `31707507497` passed for the accepted implementation SHA.
- Independent Sol final decision: `GO FOR MILESTONE 5`.
- Milestone 5 — Scenario / Notification Engine is next and has not started; no M5 implementation work is recorded.
- Non-blocking M4-15 follow-up: a future focused regression may add a direct fresh-booking attempt with Service Catalog disabled and assert that no `Booking`, `BookingEvent`, or `AuditEvent` is created. This is not an M4 acceptance blocker and does not reopen M4.

## Milestone 5A — Scenario / Notification Engine foundation

- Started from the M4 documentation closeout commit `7b94712f3b50089e221ff7d42ee0327bb89b38e3`; accepted M4 implementation SHA `9730d1fca0137c063deabaf853b94e80f54a0936` remains closed and is not being re-audited.
- The M5A plan was created at the starting base and completed. M5A now provides the durable organization-scoped scenario event/outbox boundary, typed configurable rules and allowlisted conditions, localized immutable template versions, verified recipient/channel resolution, durable scheduled actions, idempotent delivery attempts, CRM configuration/history, and one Booking `COMPLETED` vertical slice.
- Booking completion writes the ScenarioEvent in the same transaction as Booking/BookingEvent/AuditEvent mutation. PostgreSQL state/row locks claim events, actions, and delivery attempts; queue jobs carry identifiers only; Redis/Horizon is not the correctness source.
- Rules and templates are versioned through Application actions with allowlisted AuditEvent metadata. Actions snapshot source event, rule version, recipient, channel order, exact template version, render context, and schedule instant. Delivery outcomes are typed, fallback is ordered, retries retain the durable key, and current-state changes suppress history without deletion.
- Filament provides organization-scoped rule/template configuration and safe scenario-action history. Livewire persistent middleware preserves server-derived organization context for CRM mutations. Staff may inspect scenario history; management mutations require the scenario permission.
- Final local M5A verification: `make quality` passes with 21 Unit tests/43 assertions and 158 Feature tests/868 assertions; Pint, ESLint, Larastan (0 errors), vue-tsc, Vite, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. `make ci` passes with healthy PostgreSQL/Redis and 45 PostgreSQL integration tests/100 assertions. Full Playwright passes 8/8 desktop/mobile tests, including the new scenario CRM flow. Focused M5A PostgreSQL concurrency passes 2/2 tests/9 assertions.
- M5B and later milestones remain unstarted. No Finance, Medical, Surveys, RAG, AI, Broadcasts, subscriptions, payments, MAX, Instagram, calendar/video provider, or production-hardening behavior is in scope.
- The normalized requirements do not define the conditional +72h seed condition. It remains a narrow M5B owner decision; M5A does not guess or implement it.

## Milestone 5A narrow remediation

- Started from reviewed implementation SHA `7633eef136a3280ed7a2348598f2af67b3fe9f2b`; scope remained limited to the six confirmed M5A blockers.
- Retry exhaustion now terminalizes only the exhausted delivery and continues to an eligible fallback. Stale pre-send claims and states between terminal primary and pending fallback return to durable retry discovery, while a genuinely in-flight unknown attempt remains terminally uncertain.
- Internal delivery revalidates active same-organization membership and verified identity. Typed condition configuration rejects malformed structures and invalid booking-status/language values and fails closed during execution.
- ScenarioActions persist the materialized typed condition snapshot, so later rule edits do not rewrite older action semantics. Filament template edits derive immutable identity from the scoped record, create the next immutable version, and preserve existing action pins.
- Focused M5A remediation and prior M5A Unit/Feature coverage passes with 41 tests/145 assertions. Focused PostgreSQL process-concurrency coverage passes with 2 tests/9 assertions. `make quality` passes with 27 Unit tests/49 assertions and 174 Feature tests/929 assertions; Pint, ESLint, Larastan (0 errors), vue-tsc, Vite, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. `make ci` passes with 45 PostgreSQL integration tests/100 assertions. Focused scenario Playwright passes 2/2 desktop/mobile tests including Filament template version editing.
- The Telegram blank-token classification follow-up was not changed. M5B/M6+ remain unstarted, the undefined +72h condition remains unimplemented, and `.codex/config.toml` remains unchanged.

## Milestone 5A closeout

- Milestone 5A is CLOSED / ACCEPTED after final independent recheck. All six confirmed M5A blockers are independently verified fixed.
- Final accepted M5A implementation SHA: `a20379cda0068873f275c86e593eb727436500ed`.
- Hosted exact-SHA CI run `31736555888` passed on its first attempt: quality/integration, Playwright desktop/mobile, privacy/secret scan, and Docker runtime health.
- M5B remains unstarted. Staging review deployment is available through the guarded `make deploy-staging` target; production `make deploy` and `make rollback` remain blocked until M16.

## Product UX / staging review pass

- Started from `37dcae3bbf40edb58924c6ca5675569a85c5e554`; productized the existing Russian Portal and Filament CRM surfaces without starting M5B or later functionality. Technical values are now hidden, translated, or derived at the trusted Application boundary while identity verification, organization isolation, idempotency, concurrency, audit, immutable revisions, scheduling, and durable scenario guarantees remain in place.
- Added the durable `.ai/rules/product-ui.md` rule and path mapping, server-side booking idempotency derivation, Russian business labels/statuses/errors, human-friendly timezone controls, safe scenario/template/content controls, focused Portal/CRM regressions, and desktop/mobile Playwright coverage.
- Final local verification on `33fd6c59d461a7f54d778e1bc355e820c1840ca3`: `make quality` passes with 27 Unit tests/49 assertions and 189 Feature tests/1046 assertions; Pint, ESLint, PHPStan (0 errors), vue-tsc, Vite, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. `make ci` passes with healthy PostgreSQL/Redis and 45 Integration tests/100 assertions. Full Playwright passes 14/14 desktop/mobile; focused Portal/CRM/Scenario PHPUnit coverage passes 62/62 tests/369 assertions.
- The first staging attempt safely rolled back after a false host-listening-port comparison caused by trailing whitespace dependent on PID width. The normalizer was corrected and syntax-checked; the second deployment succeeded at exact SHA `33fd6c59d461a7f54d778e1bc355e820c1840ca3`.
- Real staging smoke passed for health, database/pgvector/Redis/private storage, Horizon, nginx/protected-service baselines, Russian Portal entry (`/`), Telegram/email controls, CRM login (`/admin/login`), authenticated-route guards, and Telegram web status expiry. Staging host: `https://crm.psysoldatov.ru`.
