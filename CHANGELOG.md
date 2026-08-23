# Changelog

- CI policy now uses a manually dispatched heavy candidate gate instead of automatic `push` and `pull_request` runs. Quality/integration, privacy/secret scanning, and Docker/runtime job contents are unchanged; Playwright remains separate, nightly/manual, and non-blocking.

All notable implementation changes are recorded here. Requirement changes belong in `docs/product/requirements-changelog.md`; architectural rationale belongs in ADRs.

## [Unreleased]

- Independent Stage 10.1 remediation candidate now snapshots a digest of every executed evaluation input, canonicalizes compatible datasets, refreshes human-review metrics from the bounded underlying-run provenance, separates unknown and mixed-currency provider costs, derives estimated currency from immutable pricing provenance, distinguishes same-candidate retries from failover transitions, fails closed on malformed evaluation schemas, uses immutable history labels, exposes token/RAG summaries in Russian UX, and explicitly marks the optional judge as not configured. Focused AI/Knowledge tests, Pint, PHP syntax, targeted Larastan, Composer audit, npm audit, and Blade compilation pass locally; PostgreSQL integration, hosted CI, deployment, and merge were not performed.

- Independent UX-C5 closeout remediation (requires fresh review) now fails closed on unknown billable meters, preserves exact fixed-point pricing without compatibility truncation, rejects unsafe custom endpoint targets and redirects, keeps Gemini credentials out of URLs, bounds and capability-conserves dynamic discovery, rejects provider-incompatible modalities, hides unwired Knowledge/specialized provider settings, and marks scheduled-shutdown Gemini 3.1 Flash-Lite as historical-only. No hosted CI, deployment, or merge was performed.

- UX-C5 remediation now uses a runtime-verified provider capability matrix, current guided catalogs for mainstream providers including DeepSeek, official bounded discovery for OpenRouter/OpenAI-compatible/Ollama, separate Knowledge embeddings/reranking choices, modality-compatible direct-attachment failover, exact fixed-point pricing with conservative tiered budget reservation, immutable pricing provenance, and tenant-safe credential-bounded discovery. No hosted CI, deployment, or merge was performed.

- UX-C5 AI Administration (implementation candidate):
  - Productized organization-scoped AI safety, provider, model/release, prompt/version, and evaluation administration with human labels, bounded lookups, immutable technical identities, exact monetary hydration, and preserved application/runtime lifecycle boundaries.
  - Added the shared 12-provider catalog used by CRM and the provider factory, exact decimal-to-minor-unit conversion for budgets/pricing, safe OrganizationCredential selection, normal/advanced model and prompt settings, and focused Feature coverage. No migration, OQ-015 content, M11, Finance, Portal, Playwright, benchmark, hosted CI, deployment, or merge work was added.
  - Verified the prior Filament lookup P1 against the installed 5.7.6 Select implementation and actual dynamic-search path: `CODEX P1 NOT REPRODUCIBLE`; CRM production code was unchanged.
  - Staging remediation adds owner-facing provider connection, catalog-first model selection with custom fallback, catalog/manual/unknown pricing states, human capability and failover controls, generated prompt/evaluation identities, progressive-disclosure generation and safety settings, and regression coverage for secrecy, tenant scope, exact values, and immutable runtime contracts.
  - Independent self-service hardening scrubs API-key state after create, rejects malformed catalog minor units, resets catalog/custom metadata at identity transitions including manual overrides and stale modalities, preserves legacy manual modalities, and aligns bundled Anthropic/Gemini pricing and document support with current provider documentation.
  - Final model-state remediation makes guided, custom, and legacy transitions explicit across create/edit/release paths; preserves user-owned display, failover, use-case, and enabled state; validates technical modalities and exact money boundaries; persists catalog pricing provenance; and fails closed on stale catalog metadata, retired selections, identity collisions, and historical-release mutation.
  - Follow-up verification remediation keeps current catalog modalities, pricing, and provenance authoritative for omitted and forged same-guided edits while preserving user-owned configuration state.

- CRM lookup/navigation cleanup:
  - Fixed Client view title and breadcrumbs to use the client name without the generic `Просмотр` level, with an ID fallback for blank names.
  - Added bounded, organization-scoped initial choices to Bookings, Session Specialist, Specialist-Service Assignment, and Session attachment lookups while preserving search and selected IDs.
  - No migrations, Finance behavior, UX-C5, domain lifecycle, Playwright, Docker, hosted CI, deployment, or merge work was added.

- UX-C4 Finance:
  - Reworked the Finance CRM around human-facing `Оплаты`, `Расчёт по визиту`, `История оплат`, and `Настройки валют` labels while preserving the accepted append-only ledger, reconciliation, currency, audit, authorization, and tenant boundaries.
  - Added a bounded organization-scoped Finance list projection with shared fail-closed reconciliation semantics, settlement-safe payment defaults, organization-timezone payment entry, paginated payment history, manual compensating corrections, and read-only legacy fake-payment history.
  - Added Finance summaries and payment entry points to Client and completed Booking contexts, removed the fake payment action from normal CRM, and added focused UX, tenant, correction, currency, timezone, and bounded-query coverage. No migration, real provider, Playwright, benchmark, deployment, or UX-C5 work was added.
  - Remediated currency-form state normalization across single/multi transitions, fail-closed status filtering and Client balance summaries, expected-vs-unexpected finance presentation failures, malformed legacy currency reads, and bounded preloaded Client/Service filters. Preserved current exchange-rate rows while single-currency mode hides them and kept immutable obligation/ledger history unchanged.
  - Final bounded hardening makes persisted Finance history canonical-only, validates immutable snapshot scale and same-currency identity in PHP and PostgreSQL SQL, rejects forbidden direct corrections before idempotency side effects, blocks saves over corrupt current currency/rate configuration, and presents corrupt CRM amounts as unavailable. Generic obligation audit metadata no longer duplicates a financial amount.
  - Deferred follow-up hardening: Portal Finance pagination/exact large-integer browser transport, broad legacy-data repair, and exact SQLite financial arithmetic remain outside UX-C4; PostgreSQL integration remains the authoritative financial-math gate.

- UX-C3 knowledge productization:
  - Added human source availability and newest-revision processing presentation, metadata-only edits, safe latest-failed manual retry, immutable ingestion-attempt history projection, and tenant-scoped original-file downloads.
  - Kept `KnowledgeIngestionRun::attempts` as the sole claim/fencing authority, preserved `PgvectorKnowledgeRetriever` and Laravel Filesystem storage, and removed raw provenance/retrieval implementation details from normal Knowledge CRM screens.
  - Added focused lifecycle, provenance, retry, fencing/history, download, presentation, and PostgreSQL schema coverage. No authored-content download, new storage abstraction, retrieval rewrite, Playwright, benchmark, staging, or hosted CI was added.
  - Added an explicit `Переобработать для поиска` path for ready material missing a compatible current embedding run, plus compensating recovery when manual retry dispatch fails. Existing run fencing and automatic queue retry behavior remain unchanged.
  - Final durability remediation treats only recent compatible processing as actively busy, reclaims null-start stale runs through the existing claim path, and adds `Запустить обработку` for latest pending material. Create/replacement enqueue failures remain persisted and recoverable without exposing queue details.

- UX-C2 survey builder:
  - Replaced raw definition, question, option, metric, condition, scoring, compatibility, and threshold fields with human questionnaire-builder controls while preserving canonical survey JSON.
  - Added stable generated nested identities, tenant-safe reference validation, locked-draft publish validation, compatibility-scale lifecycle handling, and human threshold-result display.
  - OQ-015 questionnaire and clinical scoring content remains unchanged and blocked.

- UX-C1B service pricing and media closeout:
  - Replaced CRM exposure of price_minor with exact major-unit price entry and edit hydration while retaining integer minor-unit persistence and explicit currency.
  - Added organization-scoped JPG/PNG service uploads, HTTPS-only external image URLs, managed-media cleanup after commit, and canonical legacy/managed/external image resolution.
  - Scenario Rules were reviewed and intentionally left unchanged because the existing information hierarchy and semantics already meet this slice.
  - Remediated portal booking price rendering for non-two-decimal currencies and made managed-media cleanup failures observable without changing persistence failure authority.
  - Final remediation keeps schedule-impact digests stable across media changes, prevents managed-path reuse, uses database-agnostic after-commit coverage, and provisions the local public storage link during setup.

- UX-A Client Workspace + ordinary client/global search — implementation candidate:
  - Added bounded identity-only name, email, exact-ID, and canonical phone search with organization/phone and PostgreSQL fragment indexes plus a resumable legacy phone-key backfill command.
  - Composed the Filament Client Clinical Cockpit from owning-module Application reads for ordinary profile data, medical profile, Sessions, Bookings, surveys, private attachments, communication status, self-booking restriction, and bounded balance summary.
  - Removed contextual medical actions from the Clients table, made attachment upload resolution organization-scoped before authorization, and stopped ordinary Client rendering from decrypting repeatedly, loading unbounded attachments, or generating private URLs.
  - Follow-up responsive remediation fixes the Client Surveys relation query for Filament 5, shortens cockpit tabs, groups booking restriction actions, stacks client tables on mobile, wraps long profile/file values, and reports attachment scan outcomes without bypassing private quarantine. A PDF remains unavailable until the configured security scanner clears it.
  - UX-A2 medical discovery, public media upload UX, themes, CRM-wide IA cleanup, finance redesign, AI productization, and M11 remain out of scope. Independent acceptance and hosted CI are pending.

- M10 AI Components & Control Plane — CLOSED / ACCEPTED:
  - Accepted main SHA: `a0746bad2d4e0695d80ff8689c49b1c57a24161e`; PR #8 was squash-merged from accepted head `6def6a6c4c2fb591401a10feaea2b3a11f92c549`.
  - Final safety/privacy/concurrency remediation and hosted evidence were accepted: Quality `32112766821` GREEN; Integration concurrency `32112766462` GREEN; 22 tests / 116 assertions.

- M10 Terra remediation:
  - Persisted immutable asynchronous candidate/failover snapshots with credential-revision, provider-configuration, and pricing provenance, plus fenced pre-I/O revalidation.
  - Forced OpenAI Responses executions to `store=false`, corrected the first Kill-Switch transition, and completed private cleared medical attachment execution for documents and three-photo posture analysis.
  - Replaced the credential revision migration backfill with a bounded resumable command and fail-closed legacy-null runtime handling; the final NOT NULL contract remains a later rollout step after backfill completion.

- M10 AI Components & Control Plane implementation candidate:
  - Added provider-neutral AI engine, prompt studio, versioning, evaluation suites, human review workflows, and RAG/tool slice for `REQ-AI-001` through `REQ-AI-008`.
  - Implemented protected Class C trace separation (`ai_run_payloads` encrypted via `MedicalEncryptorInterface`) so that `ai_runs` stores strictly operational, non-sensitive provenance metadata with no plaintext medical leakage.
  - Implemented exact credential revision tracking (`revision_id` UUID on `OrganizationCredential` and rotation snapshots in `AiRunAttempt`).
  - Implemented concurrency-safe atomic daily spend budget reservation and settlement in `AtomicAiSafetyBudgetManager` with pessimistic row-level locking (`SELECT ... FOR UPDATE`), fail-closed threshold enforcement, and conservative charging on uncertain timeouts.
  - Implemented async worker fencing with TTL lease checks preventing runaway background execution.
  - Implemented Prompt Studio with drafting, activation, retirement, rollback, playground execution, and JSON bundle export/import.
  - Implemented Filament Clinical Cockpit CRM control plane under `Искусственный интеллект`: Monitoring & Kill-Switch Overview, AI Runs with Infolists & Review Actions, Prompts & Versions, Providers & Model Releases, and synthetic Evals testing suites.
  - Added feature tests covering workflow execution, protected trace encryption, atomic budget reservation, prompt version lifecycles, human reviews, and evaluation suites.
  - Added organization-scoped authored/private-upload sources, immutable revisions, durable retry-safe ingestion runs, deterministic versioned chunking, provider-neutral embedding generation, and PostgreSQL/pgvector exact cosine retrieval behind `KnowledgeRetriever`.
  - Added composite tenant constraints, configuration provenance, active-ready atomic exposure, retirement/reactivation, sanitized structural audits, explicit knowledge permissions, and fail-closed embedding compatibility.
  - Added Filament source/version/ingestion management and a bounded retrieval inspection page. Retrieved instruction-like content remains inert data; M9 creates no AI answer, prompt studio, agent, or `AiRun` behavior.
  - Added deterministic evaluation, feature/unit regression coverage, and hosted-only PostgreSQL/pgvector/concurrency tests. OQ-008 remains open, so platform-shared method knowledge is not ingested; M8 remains blocked by OQ-015, and M10 closeout is recorded above.
  - Reorganized the Filament sidebar into business groups and placed knowledge management and retrieval inspection together under `Контент и знания`.

- M10 high-risk remediation candidate:
  - Centralized organization-owned typed input-reference validation, bounded prompt/RAG/tool-loop admission, immutable release pricing reservations, conservative fenced settlement, durable tool provenance, scheduled lease reclamation, fixed human-review reason codes, real provider connectivity semantics, exact evaluation release pinning, fail-closed eval privacy, PostgreSQL-safe async idempotency, activation permission separation, RAG failure/context controls, provider-reported usage provenance, tenant-safe eval prompt ownership, explicit medical actors, and platform-clamped organization safety limits.
  - M9 remains CLOSED / ACCEPTED. The M10 candidate was followed by independent remediation review and hosted acceptance; PostgreSQL concurrency coverage is present but is not claimed as locally executed.

- M10 final bounded remediation pass:
  - Added a whole-run deadline/lease/queue timeout contract, accumulated multi-step provider exposure reservation, ownership-independent stale-attempt reconciliation, credential-revision/configuration-bound provider health, canonical non-redirecting probes, synthetic-only eval execution, fixed-scope tool RAG, durable tool-chunk provenance with retention-safe foreign keys, a finite platform daily-spend ceiling, and fail-closed immutable prompt-version execution.
  - The final bounded remediation pass was accepted in the M10 closeout above; PostgreSQL concurrency coverage is present and is not claimed as locally executed.

- M10 final three targeted remediations:
  - Recomputed the immutable retrieval deadline before every bounded PostgreSQL metadata/vector statement and added cumulative timeout regression coverage.
  - Added application-owned Bedrock text/embedding gateways with AWS SDK transport retries disabled for one-invocation/one-attempt billing safety.
  - Persisted immutable embedding runtime/pricing snapshots through AiRun preparation, initial/tool RAG execution, fail-closed compatibility checks, and tool settlement. The PostgreSQL regression is present but not run locally under the SQLite local-light environment.

- M10 bounded execution and cost-boundary remediation:
  - Established durable preparing claims before asynchronous context/RAG work, one immutable whole-run deadline from preparation through finalization, request-scoped embedding timeouts, PostgreSQL-local retrieval statement timeouts, strict post-deadline tool provenance fencing, and bounded RAG query input.
  - Added immutable supported text billing meters (input, output, cache read/write, reasoning, and fixed request), explicit unsupported-meter fail-closed activation, organization-daily-budget reservations for initial/tool retrieval embeddings, and separate retrieval settlement provenance.
  - Added focused AI/Knowledge coverage plus PostgreSQL statement-timeout and duplicate-async-RAG concurrency tests; PostgreSQL tests are present but not run locally under the local-light verification policy.

- M8 Surveys / Road Map implementation candidate:
  - Added organization-scoped versioned definitions, immutable exact-version attempts, encrypted sensitive snapshots, typed fail-closed conditions/scoring, deterministic thresholds/tags, non-AI reports, compatibility-gated comparison, and idempotent Scenario events for completion and configured stagnation.
  - Added structured Filament definition/version publication and attempt history, responsive Inertia Portal flows, and the validated `surveys:import` path.
  - The approved local source omits the full 9-systems and MSQ questionnaires/scoring and refers to absent separate documents. No clinical content was fabricated; M8 remains content-blocked by OQ-015.

- M7 final product slice — Session files and longitudinal dynamics:
  - Added the `medical_session_attachments` association with composite Organization/Client foreign keys to both Sessions and existing medical attachments. The relation preserves historical file records, copies no bytes, and unlinking removes only the association.
  - Added authorized Application actions for bounded client-attachment search, link/unlink, linked metadata with existing temporary download URLs, and deterministic current-versus-immediately-previous Session dynamics.
  - Extended Session detail with linked file metadata/download availability, bounded specialist-confirmed longitudinal comparison, file association actions, and direct return to client Session history. No AI, diagnosis, scoring, or automated clinical judgement was added.
  - Added focused feature, PostgreSQL constraint, and desktop/mobile CRM Playwright coverage. M7 is CLOSED / ACCEPTED at `e003a7056ce14c4ef55ea69b2e48c866f3156c36` after post-merge hosted CI run `31957913038` succeeded for that exact SHA. Scheduled/manual Playwright remains non-blocking and is not claimed as closeout evidence; M8–M10 remain NOT STARTED.

- Developer workflow remediation — local-light / hosted-heavy verification:
  - AGENTS.md now defines local development feedback (targeted tests, lint, formatting) vs authoritative hosted CI (full `make quality`, integration, Playwright, Docker runtime, privacy/secret scan). Agents must not run heavy verification locally unless the user explicitly requests it.
  - Added `workflow_dispatch` trigger and branch-level concurrency cancellation to `.github/workflows/ci.yml`.
  - Added `make check-fast` target for lightweight local feedback (unit + feature + lint + static, no Docker/Playwright/containers).
  - Updated `docs/testing/strategy.md`, `.agents/skills/testing/SKILL.md`, and `README.md` to reference hosted CI as the authoritative verification path.

- M7B.2 — CRM Session Cockpit UI + client-scoped Session history (implementation / verification candidate):
  - Added the Client → Sessions history, create, detail, and edit CRM flow using the existing `CreateSession`, `GetSession`, and `UpdateSession` application actions and nested Filament resources.
  - History uses the `ListClientSessions` application boundary through the real Client relationship, selecting structural metadata only, eager-loading specialist/booking display metadata, enforcing fixed-client organization scope, SQL pagination, and deterministic newest-first ordering.
  - Specialist and optional Booking selectors use bounded server-side searches with organization, client, and selected-specialist boundaries; invalid identifiers become validation failures. M7 remains IN_PROGRESS; M7B and `REQ-MEDICAL-001` remain incomplete pending independent review and hosted CI.

- M7B.1 — Durable Medical Session Foundation (implementation candidate):
  - Added `medical_sessions` PostgreSQL table with composite-tenant identity `(organization_id, id)` and composite foreign keys to `clients`, `specialists`, and an optional nullable composite foreign key to `bookings`, mirroring the M7A `medical_profiles` and M4 `bookings` patterns. `specialist_id` is NOT NULL (responsible practitioner); `booking_id` is nullable (optional origin reference). Added justified index `(organization_id, client_id, occurred_at, id)` for future chronological client history.
  - Session-specific Class C clinical columns (`pain`, `tests`, `observations`, `root_cause_hypothesis`, `protocol`, `result`) are encrypted at rest via the existing dedicated `MedicalEncryptorInterface` / `MedicalKeyResolverInterface` primitives (`encryptField` / `decryptField`), reusing ADR-017's key/version boundary independently of `APP_KEY`. No anamnesis column is added to `medical_sessions` — longitudinal anamnesis remains owned by M7A `medical_profiles`.
  - Added `Sessions` module under `app/Modules/Sessions/{Application,Domain}` with `MedicalSession` Eloquent model, `EncryptedSessionPayload` value object, `MedicalSessionAuthorization`, `CreateSession`, `GetSession`, `UpdateSession` Application actions, and `MedicalSessionData`, `CreateSessionCommand`, `UpdateSessionCommand` DTOs. No Filament UI, no list/history projection, no delete action, no AI schema.
  - Registered `medical.session.created` and `medical.session.updated` audit actions in `RecordAuditEvent::ALLOWED_METADATA_KEYS` with allowlisted scalar metadata only (`source`, `key_version`, `booking_id`, `client_id`, `specialist_id` for create; `source`, `key_version`, `updated_fields` for update serialized as `implode(',', $names)`); no array values; no clinical plaintext is recorded.
  - Extended log redaction with a dedicated narrow Session-clinical matcher: exact context-key match for `pain` / `tests` / `observations` / `root_cause_hypothesis` / `protocol` / `result`, plus a defense-in-depth message-text matcher that redacts ONLY explicitly namespaced clinical labels (`medical_session.<field>=...`, `medical_session.<field>: ...`, `clinical.<field>=...`, `clinical.<field>: ...`). Bare operational labels (`Result:`, `test:`, `tests=`, `protocol:`, etc.) are deliberately NOT classified; the general sensitive-key pattern was not broadened with `test` / `result` / `session` / `protocol` / `clinical` operational terms.
  - `UpdateSession` uses full clinical snapshot semantics: `UpdateSessionCommand::fromArray()` requires all six clinical field keys explicitly (each value may be null to clear that field); missing keys are rejected. Audit `updated_fields` is computed from actual on-disk decrypted value differences, including explicit clears. `CreateSessionCommand::fromArray()` rejects missing/empty `occurred_at` rather than defaulting to current time. The authorized `Client` argument to `CreateSession::handle()` is the sole client authority; `clientId` was removed from `CreateSessionCommand`. `occurred_at` and structural ids remain immutable in `UpdateSession`.
  - M7B Session Cockpit UI, AI suggestions (M10), Session↔attachment linking, M8+ surveys, RAG, and any client-facing Session UI remain out of scope and unchanged.
  - M7 remains IN_PROGRESS; M7B and REQ-MEDICAL-001 remain incomplete. Local verification was limited to focused sqlite-backed PHPUnit feature tests, M7A/M1 regression filters, Larastan on changed `app/` paths, and Pint on changed files; PostgreSQL integration tests were NOT run locally and await hosted CI.

- CI bug fixes:
  - Fixed `docker/php/entrypoint.sh` shebang from `#!/bin/sh` to `#!/bin/bash` (`wait -n` requires bash, not available in dash on Debian bookworm).
  - Fixed `PerformanceBoundedQueryTest` booking time spacing to avoid `bookings_no_specialist_overlap` exclusion constraint (60-min duration + 15-min buffer = 75-min blocking range requires > 1-hour spacing).

- CRM Performance & Staging Runtime Remediation:
  - Enabled Filament SPA mode (`->spa()`) with binary attachment and receipt download URL exceptions in `AdminPanelProvider`.
  - Replaced single-threaded PHP CLI built-in web server with production-like concurrent `php:8.5.9-fpm-bookworm` + Nginx container runtime on port 8000 with clean PHP 8.5 OPcache configuration and a fail-fast PID 1 process supervisor.
  - Added repository-tracked staging host Nginx reverse proxy template in `docker/host-nginx/chuklov-staging-tls.conf` serving static fingerprinted assets (`/build/*`), fonts, brand, and published vendor styles directly with long-lived immutable cache headers while proxying dynamic application endpoints.
  - Eliminated duplicate reads and decryption on Client View infolist via request-scoped memoization in `GetMedicalProfile` (keyed by `actor_id:org_id:client_id`) with strict per-read authorization evaluation and cache invalidation in `UpdateMedicalProfile`.
  - Remediated repeated queries in CRM Clients and Bookings lists by resolving organization context directly in `ClientPolicy` and `BookingPolicy`, request-scoped memoization in `OrganizationFeatureGate` and `GetBookingLeadTime`, and context-scoped cache invalidation.
  - Added regression test suite `tests/Feature/PerformanceBoundedQueryTest.php` and Playwright E2E SPA navigation test verifying DOM preservation across route transitions.

- M7A Medical Profile + Medical Encryption + Private Attachment Security Foundation implementation candidate:
  - ADR-017 accepted and recorded.
  - Forward-only additive PostgreSQL migrations for `medical_profiles` and `medical_attachments` with composite foreign keys `(organization_id, client_id)` referencing `clients(organization_id, id)` and `(organization_id, uploaded_by_user_id)` referencing `organization_memberships(organization_id, user_id)`.
  - Dedicated versioned medical encryption secret outside database/APP_KEY dependency via `config/medical.php`, with `MedicalKeyResolverInterface` / `AppKeyMedicalKeyResolver` and `MedicalEncryptorInterface` / `MedicalDataEncryptor` supporting key versioning and rotation.
  - Class C sensitive clinical fields (`anamnesis`, `complaints_goals`, `operations_injuries`, `medicines`, `supplements`) encrypted at rest with AES-256-CBC and HMAC-SHA256 authenticated envelopes.
  - Application actions `GetMedicalProfile` and `UpdateMedicalProfile` with strict organization authorization and length validation (<= 10,000 chars per field).
  - Private attachment storage on disk `private` under UUID-named paths `medical/attachments/{organization_id}/{uuid}.{ext}` via `AttachmentStorageInterface` / `PrivateMedicalAttachmentStorage` with configurable file size limit (`MEDICAL_ATTACHMENT_MAX_BYTES`, default 20 MB recorded in ASM-008).
  - Server-side MIME sniffing (`finfo`), explicit raw DICOM rejection (magic bytes `DICM` at offset 128, MIME, and extension), and SHA-256 checksum tracking.
  - Attachment taxonomy restricted to confirmed types: `MedicalReport` ('medical_report') and `PosturePhoto` ('posture_photo').
  - Fail-closed runtime scanner `FailClosedAttachmentScanner` quarantining unconfigured uploads; deterministic scanner `LocalDeterministicAttachmentScanner` retained solely as a test fake.
  - Authorized temporary signed download URL generation `GetTemporaryAttachmentUrl` and controller `AdminMedicalAttachmentController` (`GET /admin/attachments/{uuid}`) requiring valid signatures, staff authorization, and cleared scan status without exposing storage paths.
  - Clean client save boundary in CRM: ordinary client profile editing never touches medical ciphertext; medical profiles are managed via dedicated modal action and explicit application service.
  - Security allowlists updated in `RecordAuditEvent` (`medical.profile.created`, `medical.profile.updated`, `attachment.uploaded`, `attachment.downloaded`); Monolog log redactor `RedactSensitiveLogData` updated to mask medical field names; scan metadata sanitized to allowlist keys.
  - Comprehensive unit, feature, PostgreSQL integration, and Playwright tests. M7B Session Cockpit and M8+ remain unstarted.

- M6 is recorded as CLOSED / ACCEPTED after independent remediation re-review returned GO FOR M6 CLOSEOUT. Accepted application SHA `72b4987124c4897bb6fe34bd74443848f6f85eea`, hosted exact-SHA CI run `31884758963`, and staging SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` match; the rollout/currency configuration and mixed-FX open-balance blockers are CLOSED. `REQ-CURRENCY-001`–`REQ-CURRENCY-003` and `REQ-PAYMENT-001`–`REQ-PAYMENT-005` are accepted for M6; `REQ-PAYMENT-006`/OQ-005 remain future M13 work, OQ-014 remains OPEN, and M7+ are NOT STARTED.

- M6 Finance / Multi-Currency implementation candidate: exact integer-minor-unit Money with centralized currency precision, organization-scoped base/display/payment/settlement configuration, manual FX rates and immutable conversion snapshots, fixed-price precedence, completed-booking financial obligations, append-only payment ledger with corrections, manual and partial payments, derived receivables, private receipt evidence, deterministic fake PaymentGateway initiation/settlement/reconciliation, and CRM/Client Portal finance projections.
- M6 reuses the existing Scenario Engine for organization-scoped debt events and configurable outstanding-debt conditions with execution-time rechecks; no second reminder or delivery subsystem was added. The implementation uses no real payment provider, leaves `REQ-PAYMENT-006`/OQ-005 for M13, preserves accepted M5B behavior, and does not start M7+.
- M6 verification reached hosted exact-SHA CI run `31877550150` for application SHA `b0bacaf4130f192f95dea07744f8560ebaf4b611` and guarded staging verification of finance, CRM, Portal, runtime, and responsive surfaces.
- M6 remediation candidate adds an organization-scoped, idempotent currency bootstrap for existing priced Services, atomic validation of required directed FX paths, and immutable obligation-snapshot valuation for open balances. Payment-time FX snapshots remain historical ledger evidence; invalid valuation snapshots fail visibly and Portal/Scenario/CRM projections no longer mask negative derived values.
- M6 remediation application SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` passed exact hosted CI run `31884758963` (Quality/integration `95012327744`, Privacy/secret scan `95012327712`, Docker/runtime health `95012327769`, Playwright desktop/mobile `95012327733`).
- The exact remediation SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` was deployed through the guarded staging path without resetting PostgreSQL. Staging inventory had one organization with no priced Services or Finance configuration; a rollback-safe temporary pre-M6 fixture proved bootstrap/repeat safety, atomic missing-FX rejection, confirmed Booking completion, obligation-snapshot valuation across rates `90` → `200`, Portal/CRM/Scenario projections, and zero after full settlement. No real provider, payment, reminder, or delivery was used.
- M5B scenario-family integration on the accepted M5A engine: configurable post-session +24h/+48h/conditional +72h seeds, internal onboarding re-engagement via typed `onboarding.started` state, configurable retention/no-next-booking conditions, bounded repeat action snapshots, verified internal member/role recipients, and safe CRM scenario/history projections. The exact +72h business condition remains OQ-014; no survey, AI, broadcast, payment, or later-milestone behavior was added.
- M5B implementation candidate `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457` passed hosted CI and guarded staging verification, including the additive migration, idempotent twice-run seed, healthy Horizon/scheduler/Telegram runtime, and authenticated CRM configuration/history smoke without external delivery.
- Authenticated CHUKLOV Client Portal home, branded desktop/mobile shell, reusable booking/service/profile components, direct Profile destination, and RU/EN locale switching with persisted client preference.
- Organization-scoped direct Profile consent presentation that retains immutable exact-version legal evidence without exposing a mandatory onboarding wizard.
- Ordinary-browser Telegram authentication through a short-lived, single-use, browser-bound bot deep link, resolving the same verified organization-scoped Client as the Mini App while retaining passwordless email as an alternative.
- Telegram Mini App entry now submits verified initData automatically once, with a localized recoverable failure state instead of a second manual login step.
- Portal URL generation now honors the HTTPS scheme forwarded by the isolated reverse proxy.
- Telegram `/start` is registered with the Russian `Запустить приложение` description.
- Durable client/CRM copy rules that exclude developer terminology and generic product filler from ordinary user interfaces.
- Exact-revision isolated staging deployment automation with preflight baselines, validated staging database backup, atomic release switching, runtime refresh, health checks, unrelated-service comparisons, and application rollback.
- Portal service cards now support optional configured imagery, use the refreshed CHUKLOV logo/favicon assets, and present a compact reference-inspired booking flow with grouped date/time slots.
- Portal booking now follows a focused service/specialist/format/calendar/confirmation interaction: service rows replace the primary dropdown, only the selected calendar day renders time buttons, month navigation reloads authoritative availability, and slot end times stay out of ordinary client copy.
- Rebuilt the Portal booking presentation around the approved CHUKLOV reference: contained booking card, responsive dot stepper, selectable rows/chips, separated calendar and selected-day time surfaces, readable confirmation summary, and narrow Mini App layouts without clipping or horizontal overflow.

- Milestone 5A Scenario / Notification Engine foundation: organization-scoped PostgreSQL scenario events, typed configurable rules and conditions, localized immutable template versions, verified client/internal recipient resolution, ordered channel fallback, durable scheduled actions, idempotent delivery attempts, PostgreSQL-safe worker claiming, CRM configuration/history, and the Booking `COMPLETED` transactional follow-up slice. M5B scenarios and the undefined +72h condition remain out of scope.

- Milestone 4A scheduling foundation: organization-scoped specialist working hours, date exceptions, unavailable periods, configurable lead time, UTC/IANA availability projections, typed visit formats, booking persistence, immutable booking events, PostgreSQL interval conflict protection, CRM scheduling configuration, and a client-safe Portal availability read path.
- Milestone 4B booking lifecycle: explicit organization-scoped Specialist-Service assignments, assignment-safe availability and booking creation, non-blocking HOME_VISIT review requests, protected approval/rejection actions, immutable lifecycle events, organization-scoped CRM booking surfaces, and the shared Portal Service → Specialist → slot → format flow.
- Milestone 4C final booking lifecycle: organization/actor-scoped idempotent creation, configurable cutoff-aware cancellation/rescheduling, stable booking identity/history, completion and NO_SHOW outcomes, HOME_VISIT withdrawal/approval payment handoff, ONLINE manual-link management, client booking history, CRM lifecycle actions, and explicit schedule-mutation impact acknowledgement.
- Milestone 3 organization-scoped CRM management for Clients, Specialists, localized managed content, and the existing Service catalog, including audited profile/restriction mutations, membership-backed specialist staff links, IANA specialist timezones, catalog type/configuration, and integer minor-unit pricing.
- Laravel 13 modular-monolith foundation with Filament 5, Vue 3/Inertia 3/TypeScript, PostgreSQL/pgvector, Redis/Horizon, Laravel AI SDK, Nutgram, Docker Compose, CI, and reproducible lockfiles.
- Server-derived Organization context, private storage default, health endpoint, and an organization-scoped Service vertical slice shared by CRM and Client Portal.
- Repository harness, normalized requirements, architecture/operations/testing documentation, ADRs, progressive Codex skills, and quality commands.
- Milestone 0 audit regressions for Docker-context privacy, repeat-safe key initialization, Filament tenant/workflow behavior, Nutgram fake loading, privileged User fields, and Horizon metrics scheduling.
- Milestone 1 organization memberships and explicit RBAC, typed organization settings and feature controls, client/channel identity and consent foundations, encrypted rotatable credentials, safe audit events, redacted logs, and server-side isolation tests.
- Milestone 1 remediation for PostgreSQL Filament context regression, expand/contract membership migration safety, atomic audited mutations, action-allowlisted audit metadata, service-catalog entitlement enforcement, complete authentication-header log redaction, and privileged-state mass-assignment hardening.
- Milestone 2 shared responsive Client Portal foundation, versioned progressive onboarding, server-verified Telegram Mini App sessions, organization-scoped verified channel identity linking, localized Telegram bot entry, capability-aware channel boundary, and normalized conversation/message persistence.
- M2 Client Portal visual foundation with centralized warm-neutral/sage tokens, responsive layout primitives, premium wellness surfaces, accessible controls, buttons, notices, stages, and form states shared by portal pages.
- M2 passwordless email authentication with normalized hashed one-time codes, expiry, bounded attempts/rate limits, provider-neutral mail delivery, and Laravel session regeneration.
- M2 Telegram connection tokens with authentic Nutgram bot evidence, replay/expiry protection, organization/client binding, and deterministic identity uniqueness.
- M2 organization-scoped versioned legal documents, immutable published content, exact-version consent evidence, platform-controlled legal-management readiness, and centralized IANA date/time conventions with PostgreSQL timezone-aware instants.

### Changed

- Portal booking and rescheduling calendars now load the complete visible calendar month from server-authoritative availability, including policy-valid dates before the existing booking date; outside-month dates remain visibly distinct and unavailable.
- Client-visible booking, availability, rescheduling, cancellation, and timezone failures now use the shared RU/EN error mapping instead of exposing Russian-only action errors to English Portal clients.
- The 320px multi-specialist/multi-format booking path preserves readable selected service, specialist, and format context through choice, calendar, confirmation, and success states without horizontal overflow or ellipsis.
- Telegram browser-login `/start web_…` commands no longer fall through to the generic Telegram-link handler after successful authentication.
- The Portal header, browser favicon, Apple touch icon, and Filament favicon now use the supplied designer CHUKLOV logo assets; legacy and generated brand assets are removed.
- The booking time view now hides redundant one-format context controls, keeps progress labels intact at 320px, and uses a stacked mobile confirmation summary to preserve the reference composition and readable touch layout.
- A confirmed booking now ends in a dedicated result state with the selected service, specialist, date/time, and format; the calendar and availability list no longer remain underneath the success message, and booking headings/time context have explicit spacing.
- Booking details now stay compact until the client explicitly starts a reschedule; rescheduling uses the shared calendar and selected-day time grid, while the home booking card no longer shows a non-interactive reschedule label.
- Booking detail times now render once in the client's display timezone, and technical timezone/payment controls are no longer presented as part of the ordinary client action flow.
- The Portal shell now uses the full CHUKLOV logo with its inscription; the same full-mark raster is used for the browser, Apple, and Filament admin icons.
- Removed the visible four-stage client onboarding journey and generic post-authentication Continue entry points. Internal onboarding progress remains available to Application state and legacy mutation compatibility, while optional profile data is progressive and action-specific requirements remain at their business boundary.
- Authentication now redirects directly to the authenticated Portal home; login/provider status is absent from the authenticated home, and the shell leads with committed CHUKLOV brand assets.
- Portal UI strings use one RU/EN localization dictionary with a visible shell switcher; organization-owned service/legal content falls back without fabricated translations.
- Service entry links preserve the selected service, and the Portal auto-selects the only bookable specialist while retaining a selector for organizations with multiple specialists.
- Productized the existing Russian Portal and CRM journeys: removed milestone, runtime, provider, timezone, event-ledger, version, and technical-key leakage; derived booking idempotency server-side; translated statuses, errors, scheduling controls, templates, content, and CRM fields into business language; and kept the underlying security, concurrency, audit, immutable revision, and durable workflow guarantees unchanged.

- Simplified the unauthenticated Portal entry to Russian client-facing login controls and changed the bot `/start` command description to `Запустить приложение`.
- M5A remediation preserves materialized rule-condition semantics, continues ordered fallback after retry exhaustion, safely recovers stale pre-send work, revalidates active internal membership at delivery, rejects invalid typed conditions, and restores immutable template version editing through Filament.
- Filament organization resolution is persistent across Livewire updates, preserving server-derived tenant authorization for scenario configuration mutations; unsupported condition types are rejected and explicit suppressed delivery outcomes close actions without fallback.
- Milestone 3 managed content now permits multiple organization-scoped records per section and locale while preserving ordered portal rendering through a non-unique lookup index.
- Composer package metadata identifies the private client project as proprietary.
- Hosted CI now separates quality/integration, Playwright desktop Chromium/mobile WebKit, privacy/secret scanning, and Docker runtime health/Horizon gates.
- Oversized AI SDK and Inertia skills now route to version-aware Boost documentation instead of embedding package manuals.
- Setup preserves an existing `APP_KEY`; host/Compose URL documentation consistently uses port 8000.

### Security

- Organization isolation tests, admin access foundation, private filesystem configuration, safe AI fakes, and dependency audit gates.
- Docker build context is deny-by-default, Gitleaks scans reachable history and Git-relevant working files, privileged User fields are not mass assignable, and private source-history policy is explicit in the master plan.
- Telegram initData is signature-verified through Nutgram, freshness/replay checked, never trusted from frontend identity fields, and excluded from audit metadata; client sessions resolve only through the server-derived organization context.
- Email auth codes and Telegram connection tokens are short-lived, single-use or bounded, hashed at rest where applicable, absent from audit metadata, and do not select organization/client context from request input.
## 2026-08-23 — Stage 10.1 AI Evaluation Quality / Observability

- Extended the accepted AI evaluation foundation with bounded typed deterministic checks: required/forbidden information, non-empty output, JSON schema, required structured fields, bounded field values, and RAG source provenance. Unsupported assertion types fail closed.
- Stored immutable evaluation provenance and safe case-level failure categories, with run-level pass rate, quality breakdown, latency, token, retry/failover, execution-error, cost, and human-review metrics. Chuklov estimated cost remains distinct from provider-reported cost and historical pricing snapshots remain authoritative.
- Added Russian Filament quality history, protected-data-safe run detail, and compatibility-gated comparison of prompt/model evaluation runs. The optional judge layer is disabled and does not generate scores by default.
- Preserved organization tenancy, synthetic/deidentified evaluation validation, encrypted medical trace boundaries, exact prompt/model release pinning, existing RAG provenance, and fake-only normal CI policy. AI Companion, M11, OQ-015 content, provider catalog redesign, deployment, and merge remain out of scope.
