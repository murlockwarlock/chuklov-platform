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

### Milestone 10: AI Components & Control Plane

- **Objective**: Build a production-grade, provider-neutral organization-scoped AI platform foundation and monitoring/control plane for `REQ-AI-001` through `REQ-AI-008` and `docs/architecture/ai.md`.
- **Non-Goals**: Payments, SaaS billing/plans, org onboarding platform, white-label, multi-bot SaaS abstractions, speculative provider marketplace, autonomous medical decision making, automatic clinical fact writes from AI, arbitrary tool execution, fake clinical/survey production content, provider-specific OpenAI coupling.
- **Affected Modules**: `app/Modules/AI` (Application, Domain, Infrastructure, Jobs), `app/Modules/Organizations`, `app/Modules/Security`, `app/Modules/Knowledge`, `app/Filament`, `app/Providers`.

#### Key Architectural Invariants & Decisions
1. **Protected Sensitive Trace vs Generic Metadata (Class C Encryption Reuse)**:
   - `ai_runs` stores operational metadata, entity references, sha256 digests, usage totals, timings, and sanitized error categories.
   - Sensitive text (system/user prompts, AI responses, human review notes, human-edited clinical text) is stored strictly in `ai_run_payloads`.
   - **Encryption Contract**: Reuses the established project Class C sensitive-data encryption infrastructure (`MedicalEncryptorInterface` / `MedicalDataEncryptor` with integer `key_version` from `config/medical.php`). No custom cipher or key derivation.
   - **Authorization**: `ViewAiTrace` permission is checked before calling `decryptField`. Decrypted payload never enters generic logs, exceptions, audit metadata, or Filament table state.
2. **Explicit Credential Revision Provenance**:
   - `organization_credentials` adds an explicit `revision_id` column (UUID v4 string), backfilled for existing rows.
   - Rotation in `ReplaceOrganizationCredential` updates `credentials`, `last_rotated_at`, `rotated_by_user_id`, and assigns a new `revision_id = Str::uuid()`.
   - Rotation audit records safe `['provider' => $provider, 'credential_name' => $credentialName, 'old_revision_id' => $oldRevisionId, 'new_revision_id' => $newRevisionId]` without secret values.
   - `AiRunAttempt` snapshots `credential_id` (FK to `organization_credentials.id`) and `credential_revision` (exact string UUID from `OrganizationCredential::revision_id` at the moment of attempt creation).
3. **Decoupled Provider, Model & Release Lifecycle**:
   - `ai_provider_configurations`: provider-level settings, enabled toggle, health status, connection test, linked `credential_id`.
   - `ai_model_configurations`: model-level settings, display name, lifecycle status (`active`/`preview`/`deprecated`), capabilities.
   - `ai_model_releases`: immutable versioned snapshot with immutable `pricing_snapshot` (minor units per million tokens and currency).
4. **Agent Scope in M10 (No Premature DB Tables)**:
   - In M10, the runtime unit is the immutable **Prompt Version** (`ai_prompt_versions`) paired with an immutable **Model Release** (`ai_model_releases`) under an `AiCapability`.
   - No speculative `ai_agents` / `ai_agent_releases` DB tables are created. Instead, a typed `AgentRelease` Value Object defines the execution bundle `(capability, prompt_version_id, model_release_id, context_policy, allowed_tools, output_schema)`.
5. **Capability Registry vs Persistent Safety Controls**:
   - `AiCapabilityRegistry`: Static, code-defined specifications (allowed input classes, schema contracts, tool/RAG policies, default limits, `requiresHumanReview`, timeouts).
   - `AiOrganizationSafetyControl`: Persistent DB record per organization (global kill-switch, disabled capabilities, disabled providers, max tokens per run, daily spend cap, rate limit, max tool calls, attempt timeout override).
   - Invariant: Runtime controls can tighten or disable capabilities, but cannot expand permissions beyond the registry maximum.
6. **Granular Permissions**:
   - `ViewAiRuns`: view monitoring metrics & run operational metadata.
   - `ViewAiTrace`: decrypt & view protected prompt/response trace.
   - `ReviewAiProposals`: accept, reject, or edit AI proposals.
   - `ManageAiPrompts`: create/edit prompt drafts, export/import bundles.
   - `ActivateAiReleases`: activate/rollback immutable releases.
   - `ManageAiProviders`: configure providers, credentials, kill-switches.
   - `UseAiPlayground`: run test prompts in isolated playground.
   - `Staff` default role has `ViewAiRuns`, `ReviewAiProposals`, and `UseAiPlayground`, but **not** `ViewAiTrace`, `ActivateAiReleases`, or `ManageAiProviders`.
7. **Typed Origin & Subject Provenance**:
   - `origin` (`AiRunOrigin` enum): `User`, `SystemScenario`, `Playground`, `Evaluation`, `ClientPortal` (execution mechanism like worker is strictly excluded).
   - `initiated_by_user_id`: nullable FK to `users`.
   - `client_id`: optional direct FK for indexing/query scoping.
   - `input_references`: structured, allowlisted domain references (`client`, `medical_session`, `medical_attachment`, `survey_attempt`, `booking`, `knowledge_source`), validated for organization ownership before saving.
8. **Attempt-Level & Pricing Provenance**:
   - `ai_run_attempts`: records exact provider, model, `model_release_id`, `credential_id`, `credential_revision`, latency, token usage, `provider_cost_minor_units` (reported), `settled_estimated_cost_minor_units` (calculated from immutable `pricing_snapshot`), retry/failover reason, and sanitized error.
   - Provider-reported and locally estimated costs are never mixed.
9. **Durable Append-Only Provenance (Zero Plaintext Leakage)**:
   - `ai_run_attempts`: provider interaction history.
   - `ai_run_tool_calls`: tool key, call index, read-only flag, input SHA-256 digest, execution status, latency, sanitized error (zero sensitive plaintext).
   - `ai_run_rag_references`: source_id, revision_id, chunk_id, chunk_index, similarity_score, config_key (zero duplicated chunk plaintext).
   - `ai_run_human_reviews`: decision, reviewer user ID, timestamp, safe reason category. All freeform notes and edited clinical content are strictly encrypted in `ai_run_payloads`.
10. **Atomic Daily Safety Budget Reservation & Settlement**:
    - `ai_organization_daily_budgets` (`organization_id`, `usage_date`, `spent_minor_units`, `reserved_minor_units`, unique `[organization_id, usage_date]`).
    - First-row & concurrent reservation: In short DB transaction, `INSERT ... ON CONFLICT DO NOTHING`, then `SELECT ... FOR UPDATE`, verify `spent + reserved + requested <= max_daily_spend_minor_units` (fail closed on breach), `UPDATE ... SET reserved = reserved + requested`, commit.
    - Durable per-attempt reservation on `AiRunAttempt`: stores `reserved_cost_minor_units`, `budget_usage_date`, `budget_reservation_status` (`reserved`, `settled`, `released`, `conservatively_charged`), `settled_estimated_cost_minor_units`.
    - Single accounting basis: reservation = worst-case estimated cost (max tokens * model release price); settlement = local actual token cost * model release price.
    - Fail-safe uncertain outcome policy: on crash/timeout, attempt marked `conservatively_charged`, `spent += reserved_cost, reserved -= reserved_cost`.
11. **Async Worker Lease, Timeout Derivation & Fencing**:
    - Identifier-only queue job (`organization_id`, `ai_run_id`).
    - `attempt_timeout_seconds` configured per capability within registry/platform limits (default 30-60s, max 120s).
    - `lease_ttl_seconds = attempt_timeout_seconds + max(60, attempt_timeout_seconds)`.
    - Pre-attempt: acquire row lock `SELECT ... FOR UPDATE` on `ai_runs`, renew lease, create durable `AiRunAttempt` in `status = 'running'`, commit.
    - External provider call: executed **strictly OUTSIDE DB transaction**, passes provider `Idempotency-Key` where supported, bounded by HTTP client timeout.
    - Finalize: acquire row lock `SELECT ... FOR UPDATE` on `ai_runs`, verify `worker_lease_token` matches and status is `running` (fencing check). Stale worker aborts without writing output.
12. **Eval Privacy**:
    - `ai_eval_cases`: strictly synthetic or de-identified test fixtures; real patient medical plaintext and copying protected production traces into eval fixtures are strictly forbidden.
13. **Focused Tool Slice**:
    - `AiToolRegistry` with allowlisted tools, typed schemas, auth verification.
    - Reference read-only tool: `SearchKnowledgeBaseTool` backed by M9 `KnowledgeRetriever`.
14. **Modular Migrations & PostgreSQL Constraints**:
    - 3 coherent forward-only migrations:
      1. `2026_08_17_100000_create_ai_configuration_tables.php`
      2. `2026_08_17_100001_create_ai_run_provenance_tables.php`
      3. `2026_08_17_100002_create_ai_evaluation_tables.php`
    - Composite unique keys `(organization_id, id)` and strict composite FKs.

#### Implementation Sequence
1. Permissions in `OrganizationPermission.php` and `OrganizationRole.php`.
2. 3 coherent PostgreSQL migrations (Configuration & Budget, Runs & Provenance, Evaluation) and `OrganizationCredential` revision column/backfill.
3. Domain layer: Enums, Models, Value Objects, Contracts, `AiCapabilityRegistry`, `AgentRelease`.
4. Infrastructure layer:
   - `MedicalDataEncryptor` integration for `ai_run_payloads`.
   - `SafePromptRenderer` (variable interpolation with allowlist).
   - `JsonSchemaOutputValidator` (structured output validation).
   - `DefaultAiPricingCalculator` (deterministic token cost calculation).
   - `SearchKnowledgeBaseTool` (read-only M9 RAG integration).
   - `AiContextAssembler` (prompt & RAG context assembly).
   - `LaravelAiWorkflowEngine` (Laravel AI SDK execution, attempt recording, failover, atomic budget reservation/settlement, lease renewal/fencing).
   - `ProcessAiRunJob` (async worker job).
   - `RedactSensitiveLogData` (logging processor).
5. Application Actions:
   - `ExecuteAiRun`, `DispatchAsyncAiRun`, `ProcessAiRun`.
   - `CreatePromptDraft`, `ActivatePromptVersion`, `RetirePromptVersion`, `RollbackPromptVersion`.
   - `ImportPromptBundle`, `ExportPromptBundle`, `ImportPromptFromText`.
   - `PreviewContext`, `ExecutePlaygroundRun`.
   - `ReviewAiRun` (`accept`, `reject`, `edit_and_accept`).
   - `TestProviderConnection`, `RunEvaluationSuite`, `CheckAiSafetyBudget`.
   - `ReplaceOrganizationCredential` revision UUID integration.
6. Filament CRM Control Plane (Design Freeze / Clinical Cockpit):
   - `AiMonitoringOverview` page.
   - `AiRunResource` (list, detail tabs for Attempts, Tools, RAG References, Human Review, encrypted Trace gated by `ViewAiTrace`).
   - `AiPromptResource` (Prompt Studio, drafts, releases, export/import, playground).
   - `AiProviderConfigurationResource` (providers, models, credentials, test connection).
   - `AiEvaluationResource` (eval suites, cases, runner).
7. Comprehensive Test Suite (Unit, Feature, Isolation, Concurrency, Redaction).
