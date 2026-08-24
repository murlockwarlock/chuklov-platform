# AI

This document is the normative M10 product and architecture contract for `REQ-AI-001` through `REQ-AI-008`. M10 is `CLOSED / ACCEPTED` at main SHA `a0746bad2d4e0695d80ff8689c49b1c57a24161e`; normal CI and focused verification use deterministic Laravel AI SDK fakes and make no paid provider call.

## Runtime Boundary

Laravel AI SDK remains the provider-independent runtime behind an `AiWorkflowEngine`-style Application boundary. Business modules call typed AI workflows and do not issue provider-specific HTTP requests. AI behavior does not belong to Telegram, one frontend, one prompt, one model, or one provider. Provider adapters, model selection, and provider-specific behavior remain inside the AI runtime and configuration boundary.

Normal tests and CI use deterministic fakes. Paid or nondeterministic provider evaluation is a separate, explicit path and is never required for every ordinary code change.

## CRM Control Plane

M10 provides authorized CRM surfaces for:

- Agents / Workflows
- Prompts
- Providers / Models
- Runs / Monitoring
- Playground
- Evaluations

The exact Filament resource layout may vary, but ordinary supported configuration must not require PHP edits. Configurable does not mean unrestricted: all values use typed registries, validated schemas, allowlists, bounded numerical limits, and Application authorization. There is no unrestricted provider JSON, executable template code, arbitrary database access, or arbitrary tool registration from CRM.

An agent/workflow has a stable identifier, purpose, enabled state, required capabilities, provider/model policy, prompt version, generation settings, context policy, output schema, allowed tools, optional RAG policy, data/safety policy, operational limits, and failover policy. Every execution-defining change creates or references a new immutable version. Draft, test/evaluate, active, retired, and rollback semantics may use equivalent repository naming, but activation is deliberate and historical meaning never changes.

## Providers, Credentials, And Capabilities

Provider support follows project-approved Laravel AI SDK adapters and may include OpenAI, Anthropic, Gemini, Azure OpenAI, AWS Bedrock, Groq, xAI, DeepSeek, Mistral, Ollama, OpenRouter, and approved OpenAI-compatible endpoints. This list is compatibility guidance, not a requirement to implement every provider.

Credentials use the existing encrypted organization credential infrastructure. They are masked in CRM, excluded from prompts, configuration bundles, ordinary exports, `AiRun` payloads, exceptions, and logs, and support authorized replacement/rotation. A bounded authorized connection test may record only safe outcome metadata. Provider configuration may retain endpoint, region, data-handling policy, and capabilities where applicable; the system never assumes identical provider privacy, retention, training, region, tool, file, vision, or structured-output behavior.

A typed capability registry distinguishes relevant model abilities and limits, including text, vision, files, structured output, tool calling, streaming, embeddings, reasoning controls, input/context limits, and output limits. Each workflow declares required capabilities and permitted providers/models for its data classification. Configuration fails closed when requirements are not met. Unsupported settings are hidden, disabled, or rejected rather than silently forwarded.

Active configuration visibly flags unavailable or deprecated models. Historical runs permanently retain exact provider/model identifiers and effective capability/configuration provenance even after a model disappears.

## Prompts And Portable Configuration

Production prompts are versioned records, not mutable hardcoded strings. CRM supports creating a prompt, editing a draft, cloning a version, viewing history and diffs, previewing rendered content, activating, retiring, and rolling back. Activated versions are immutable and retain author, timestamps, and optional change note. Queued and running work keeps the version/configuration captured when it was created.

Prompt content supports direct editing plus bounded UTF-8 `.txt` and `.md` import/export. Import validates size, type, and encoding, creates a draft, and never replaces or activates production content silently.

A portable structured bundle, preferably JSON, may contain workflow identifier, prompt content/version, provider/model selection, generation settings, context policy, output schema version, tool references, and RAG/retrieval references. It never contains API keys, credentials, private secrets, or secret prompt variables. Import validates all referenced types and creates a non-active candidate.

Prompt templates use typed allowlisted variables. Each variable defines stable name, source, type, required state, sensitivity/classification, and permitted workflow scope. Unknown variables fail validation. Secrets are not variables, and prompt content cannot execute PHP or arbitrary template code. Authorized preview uses explicit test or permitted record context.

## Generation Configuration

Typed settings may include provider, model, temperature, `top_p`, maximum output tokens, maximum agent/tool steps, timeout, and failover order. Provider-specific controls such as reasoning effort, penalties, or structured-output options are available only when the selected capability registry supports them. Every run retains the exact effective settings used.

## Context Policy, Budget, And Provenance

Context assembly is explicit, composable, organization/client scoped, authorized, deterministic, and bounded. A policy may select first N messages, last N messages, first N plus last M, a permitted summary of the middle, bounded relevant messages, selected Client/Profile fields, latest N confirmed Sessions, selected Survey results, authorized RAG scopes, or pinned sources. Counts and priorities are configuration, not hidden business constants.

Each workflow has an application-defined input/context budget that can be smaller than the provider maximum for cost, latency, and reliability. The budget accounts where possible for instructions, conversation, Profile/Session/Survey data, RAG context, and expected/max output. Components have explicit priority. When over budget, the configured strategy summarizes where permitted, truncates lower-priority content, or fails visibly. Required high-priority context is never silently removed, and long histories cannot cause unbounded query, memory, token, or cost growth.

Context Preview lets an authorized user inspect the prompt version, rendered variables, selection rules/counts, Profile/Session/Survey sources, RAG chunks, and approximate usage before a test or activation. Medical visibility follows the same authorization boundary as the source record.

A historical run's Context Inspector exposes configuration and source provenance: context-policy version, message references, Profile/Session/Survey references, prompt version, configuration version, and RAG source/chunk references. Prefer durable source references over copying sensitive plaintext. Full protected content is shown only when separately authorized.

RAG-enabled runs retain KB/source version, retrieved source/chunk IDs, scope, and retrieval-policy version so they remain explainable after knowledge changes. Retrieved content is untrusted and cannot override system instructions, permissions, tools, safety rules, or secrets.

## Tool And Output Safety

AI receives only explicitly registered tools. Each tool defines identifier, permitted workflows, organization/client scope, read-only or mutating classification, input/output schemas, Application authorization, timeout, and safe failure behavior. Tools call normal Application actions and never expose unrestricted database, framework, storage, or provider access.

Mutating tools require a future explicit product requirement for the exact workflow plus idempotent Application implementation. Without that scope, AI cannot overwrite confirmed medical facts, alter permissions or security configuration, modify finance/payments, create/cancel/reschedule bookings, delete records, or send arbitrary external messages. Tool calls and safe result metadata are linked to the run trace.

Application-consumed AI output uses a versioned validated structured schema where appropriate. Runs retain schema identifier/version, validation status, and validated output. Invalid output becomes an explicit `invalid_output` or equivalent failure state and cannot silently become business data. Schema changes do not reinterpret historical runs.

## Durable Run And Trace

Every production or stored test invocation creates an organization-scoped durable `AiRun` or equivalent. It records, where applicable:

- organization, client/entity references, agent/workflow, and execution mode
- agent/configuration, prompt, context policy, output schema, tool set, and retrieval policy versions
- provider/model and exact effective generation settings
- source/input references and safe provider request/reference identifier
- queued, started, and finished instants plus duration/latency
- durable status and terminal outcome
- usage, cost evidence, validated result, and safe error classification
- review, feedback, replay/original-run, and cancellation references

Historical runs are immutable provenance. A later CRM edit cannot alter their meaning.

One run may contain multiple bounded model, tool, retry, or provider interactions. Durable ordered steps/attempts record type, sequence, provider/model, timing, usage, tool identity/result metadata, retry/failover state, safe failure classification, and safe provider reference. One `AiRun` is not assumed to equal one HTTP request.

The lifecycle distinguishes queued, running, succeeded, failed, cancelled, timed out, and invalid output, or exact equivalents. Partial or streamed content is never successful final output. Stale running work and retry exhaustion are detectable and lead to explicit recoverable or terminal state.

## Idempotency, Retry, Failover, And Replay

Production execution is safe under duplicate submit, queue retry, network retry, worker restart, timeout, provider success followed by local persistence failure, and competing workers. Stable execution/idempotency identity prevents harmful or expensive duplicates. Retry number, cause, exhaustion, and uncertain provider outcome are durable; Laravel Job state alone is not the business record. Recovery/reconciliation preserves uncertainty rather than guessing success.

Failover is explicit and ordered. Each candidate must satisfy capability, data policy, allowlist, safety, and cost limits. The runtime never silently selects an unexpectedly expensive or less-approved model. Every attempt records the actual provider/model and failover chain.

Replay creates a new linked run. It may reuse historical configuration or use a candidate configuration, but never rewrites the original and never automatically repeats dangerous side effects.

## Usage, Cost, Caching, And Operational Limits

Capture only provider-supported usage such as input, output, reasoning, cached tokens, or cache read/write/hit values. Cost distinguishes provider-reported, deterministic locally calculated, and estimated values; estimates are never presented as provider-authoritative billing. Calculated cost keeps enough pricing/version evidence to remain explainable.

Safe provider prompt/context caching may be used only with organization, client, and data-classification-safe keys. Medical or other classified context never crosses tenants or clients through cache reuse. Provider-reported cache effects appear in usage/cost when available.

Organization/agent/workflow safeguards may constrain allowed providers/models, request rate, concurrency, input/output/context size, per-run budget, and daily/monthly spend where calculable. These are operational safety controls, not Phase 2 subscription billing. A reached limit fails safely with a structured diagnosable reason and cannot be bypassed silently by failover.

## Playground, Comparison, Evaluations, And Release Safety

The authorized Playground tests a workflow, prompt draft/version, provider/model, generation settings, test input, context policy, and safe test context. Test/preview runs are visibly marked and cannot mutate confirmed medical facts, bookings, payments, production messaging, or normal client history. They may remain as `AiRun` records for cost, debugging, and provenance.

Candidate comparison supports prompt A/B, model A/B, settings A/B, and retrieval policy A/B where relevant, showing result, structured validation, latency, usage/cost, and evaluation outcome where available.

Curated deterministic evaluation cases cover applicable required facts, forbidden claims, missing-information behavior, source fidelity, structured-output validity, clinical safety, prompt injection, tool safety, and retrieval quality. Cases reference exact workflow/configuration and expected constraints. Material prompt/model/tool/retrieval changes have an explicit recorded evaluation path against a known baseline before deliberate production activation; paid-provider evals are not mandatory on every ordinary code change.

## Human Review And Feedback

AI clinical content remains visibly and structurally distinct from specialist-confirmed Profile and Session facts. A review lifecycle preserves generated/draft, reviewed, accepted/confirmed, rejected, or superseded meaning using repository conventions. Promotion is an explicit authorized staff action recording reviewer, decision, and timestamp. Original output and run provenance remain intact after review.

Authorized staff feedback may classify output as useful, incorrect, unsafe, missing context, or another typed reason and remains linked to the run. Production medical traces do not automatically become training or evaluation datasets. Any later use requires an explicit controlled, authorized, privacy-compliant, and where necessary de-identified process.

## Monitoring, Diagnostics, And Alerts

Authorized AI Runs / Monitoring CRM supports recent runs and filters by date, workflow, provider, model, status, execution mode, and permitted client/entity. Run detail exposes safe provider/model/configuration provenance, source references, validated output, tools, retries, failover, latency, usage/cost, errors, review, and feedback.

A compact owner/admin summary may show totals, success/failure rates, recent failures, latency, usage/spend, provider/model/workflow distribution, retries, and failover. This remains practical CRM visibility rather than a separate observability platform.

Safe error classifications distinguish provider unavailable, timeout, rate limit, quota/credits, invalid request, unsupported capability/configuration, invalid output, tool failure, retrieval failure, retry exhaustion, failover failure, persistence failure, and cancellation where supported. Raw provider exception dumps and secrets are not persisted.

Ordinary Laravel logs contain only safe identifiers and metadata such as `ai_run_id`, correlation ID, workflow, provider/model, status, latency, and safe error classification. They never contain full sensitive prompts/responses, medical or RAG plaintext, private files, sensitive tool payloads, or credentials. Protected trace belongs in the authorized run persistence boundary.

Monitoring supports attention for repeated provider failure, sustained failure rate, retry exhaustion, abnormal latency, budget exhaustion, and repeated invalid output. Prefer aggregated conditions over per-error noise; implementation may reuse existing internal notification/Scenario capabilities when explicitly designed.

## Privacy, Files, Retention, And Permissions

Every workflow/provider configuration defines which classified data may be sent externally, including Profile, confirmed Sessions, Surveys, documents, posture photos, conversations, and RAG content. The runtime sends only explicitly permitted bounded context. Cross-organization access, cross-client contamination, and prompt exposure of credentials are impossible.

Private PDF/image/document analysis uses a controlled authorized integration path, respects cleared/quarantine state and provider capability/data policy, and never makes an attachment public merely for provider access. Raw DICOM remains excluded until a future explicit requirement changes it.

Sensitive run/trace persistence is retention-aware. M10 does not invent legal periods; OQ-013 and the client-data lifecycle govern future deletion/anonymization semantics. The design must support protected provenance plus future legally required retention, deletion, or anonymization without treating every full medical prompt as permanent.

Streaming follows the same privacy rules. Browser disconnect, cancellation, or partial tokens do not imply success; only completed validated output can be consumed downstream.

AI permissions distinguish at least viewing permitted results/runs, viewing sensitive trace, managing prompt drafts, managing providers/models, managing tools, using Playground, and activating production configuration. They extend the existing organization permission architecture without prebuilding speculative RBAC. Production activation is higher risk than ordinary viewing.

## M10 Completion

M10 is complete only when implemented workflows satisfy `REQ-AI-001` through `REQ-AI-008` and are provider/model configurable, capability validated, credential safe, prompt/configuration versioned and import/export capable, context bounded and inspectable, tenant/client isolated, tool restricted, structured-output validated, durable, retry/idempotency/failover safe, cost/usage visible, operationally bounded, observable, diagnosable, safely replayable, human reviewable, clinically separated from confirmed facts, privacy controlled, evaluable, and activation/rollback capable.

Required M10 product surfaces/evidence include durable run provenance and step trace, AI Runs / Monitoring, Prompt Studio, provider/model configuration, generation and context policies, Context Preview/Inspector, Playground, comparison/evaluation, activation/rollback, usage/cost and retry/failover visibility, tenant/security/privacy coverage, medical log-redaction coverage, and adverse failure-path tests.

See ADR-008 and `docs/architecture/ai-data-flow.md`. M10 is `CLOSED / ACCEPTED` after independent re-review and final hosted evidence at main SHA `a0746bad2d4e0695d80ff8689c49b1c57a24161e`; none of this expands M7 implementation scope.

## Stage 10.2 Client Companion

The Client Companion is a client-facing workflow on top of the M10 control plane, not a provider or chatbot subsystem. Every turn executes through `AiWorkflowEngine` with `AiCapability::ClientCompanion`, immutable organization-managed prompt/model configuration, existing budget/fallback/provenance, the existing `search_knowledge_base` tool, protected trace, and the typed structured result contract. The system-owned safety policy is appended independently of organization tone/instructions and retrieved Knowledge remains untrusted data.

The server builds bounded semantic context from one organization/client/Conversation and the current context epoch. Organization settings select bounded first and recent complete exchanges; a hard character/input budget and the M10 capability limit remain authoritative. Context excludes Telegram HTML, delivery IDs, callback payloads, encrypted ciphertext, protected traces, queue state, and raw structured-result JSON. ClientCompanion input references remain limited to the approved client projection, client-safe Knowledge, and the narrow Companion image reference.

Image turns declare `AiModelModality::ImageInput` in the same `AiRunRequest`. Candidate resolution filters the organization-configured healthy ClientCompanion failover order by modality, so a text-only primary is skipped for an image while no unconfigured vision model is selected. Image bytes are resolved through the existing private attachment path, provenance/pricing/attempts remain on the same `AiRun`, and a failed image candidate can fail over only to another eligible image candidate. No voice, TTS, image generation/editing, arbitrary documents, or autonomous medical diagnosis is introduced.

Invalid structured output, unavailable configuration, budget/provider failure, or unsafe/out-of-scope input becomes a typed safe terminal state. Handoff creates a durable organization/client/Conversation escalation and pauses automation; staff resolution and intentional resume are explicit. Companion history, feedback, export, and handoff operations do not duplicate M10 usage/cost/provider truth.
