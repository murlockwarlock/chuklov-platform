---
paths:
  - '{app/Models/**,app/Http/**,app/Filament/**,app/Jobs/**,database/migrations/**,tests/Unit/**,tests/Feature/**,tests/Integration/**,tests/Support/**,app/Modules/*/Domain/**,app/Modules/*/Infrastructure/**,app/Modules/*/Jobs/**,app/Modules/*/Application/**}'
---

# Application

## Validate untrusted boundaries before Domain construction
Treat Eloquent casts and PHPDoc, JSON/JSONB, HTTP/provider/webhook/queue payloads, and persisted metadata as untrusted. Check actual runtime type and shape before constructing strict Domain value objects; never weaken Domain types to accept malformed data. Where PostgreSQL can safely enforce a fixed JSON structure, add a CHECK as defense in depth.

## Revalidate authority at the Application boundary
Resolve organization ownership server-side and revalidate mutable permission, membership, feature, and entitlement state when delayed or materialized work executes. Filament, Livewire, Vue, request fields, and hidden or disabled UI state are not trusted boundaries; derive immutable fields from authorized scoped records and reject disabled capabilities on direct Application, API, and job paths.

## Make evidence atomic and define replay first
Commit a business mutation and every required audit or domain event, outbox item, scenario event, or ledger entry in one transaction, or roll back the mutation. Before mutable checks, define the stable idempotency key, payload identity, replay result, concurrent-duplicate behavior, and side-effect boundary; a valid replay must survive later mutable-state changes.

## Freeze materialized semantics and canonicalize time
Delayed work must keep historically truthful semantics through immutable revision references or explicit snapshots for conditions, templates, prices, terms, surveys, prompts or configuration, legal content, and similar inputs. Live-stop controls must be intentional and documented. Store instants canonically in TIMESTAMPTZ or UTC, use IANA zones for wall-clock rules, and cover relevant DST, date-boundary, and large-offset cases.

## Prove races and durable failure states on PostgreSQL
Use PostgreSQL locking or constraints and focused process-level race tests when writers can compete; ordinary feature tests and transaction wrappers are not concurrency proof. Every failure path must end in an explicit terminal or recoverable durable state, including stale work, malformed data, dependency failure, retry exhaustion, fallback, and uncertain side effects; verify the next scheduler or replay cycle and never accept exception or recovery loops as fail-closed.

## Bound collection/read-path cost
For list/table/collection paths, planning and review must inspect work performed per record, including database queries; I/O; decryption; authorization; feature/config lookups; and repeated calculations. Avoid hidden O(N) work through policies; accessors; serializers/projections; callbacks; and helpers. Where material regression risk exists, use focused bounded-query or collection-size regression tests comparing different collection sizes rather than only asserting page/request success. Query count alone is insufficient — also consider rows/data fetched, payload growth, and memory/object graph growth. Do not fix N+1 by blindly eager-loading huge relationship graphs.

## Cache / memoization correctness
Any performance cache or memoization must define: scope/lifetime; organization/tenant keying where applicable; invalidation; authorization implications; and consistency requirements. Do not introduce persistent caching merely to hide an inefficient request path.

## Data growth / migration cost
For hot or append-heavy tables: consider real query patterns and indexes; consider migration locking/rewrite cost; preserve expand/contract deployment compatibility; and identify retention/archive needs for durable append-only histories where relevant. Do not introduce speculative partitioning or premature complexity.

## Async / deploy compatibility for jobs and durable workflows
Consider queue/backlog growth and retries; consider already-enqueued work across overlapping application revisions; avoid provider/network I/O inside long database transactions; and keep lock ordering and retry side effects explicit.

## PostgreSQL session state is scoped infrastructure
Helpers that change `statement_timeout`, `lock_timeout`, `search_path`, transaction isolation, advisory/session settings, or other `SET`/`set_config` state must work outside a transaction, inside a caller-owned transaction, and inside Laravel nested `DB::transaction()` calls. A Laravel nested transaction may be a PostgreSQL savepoint, not a new transaction. `SET LOCAL` / `set_config(..., true)` is not automatically scoped to a successfully released savepoint. Capture the caller's effective state and restore it when required; after a failed statement, roll back the PostgreSQL savepoint before issuing cleanup SQL. No bounded setting may leak into unrelated caller SQL.

## Absolute deadlines and timeout layers
Distinguish provider-step, tool, whole-run, queue-job, worker/Horizon, queue `retry_after`, and PostgreSQL statement timeouts. A check after an operation returns is not a hard timeout. Suboperations in one bounded operation share one immutable absolute deadline and pass only the remaining budget downstream; do not reset a fresh timeout for every step. Preserve `job timeout < worker/supervisor timeout < queue retry/re-delivery timeout`. SDK or transport retries count toward external exposure and must be explicitly bounded or disabled.

## Durable claim before external I/O
When idempotency or single ownership matters, create the authoritative PostgreSQL uniqueness/ownership claim and commit it before provider, embedding, Telegram, storage, notification, payment, webhook, or other network I/O. Use a short claim/reserve/snapshot transaction, then perform the side effect, then use a short fenced finalization transaction. Dispatch jobs after the claim commits (`afterCommit` where dispatch occurs inside a transaction). Redis locks may reduce contention but cannot replace durable business state.

## Lease tokens are fencing capabilities
A lease/token grants conditional write authority, not a permanent right to finish. After lease loss, a stale worker must not write terminal or attempt status, output, provenance, retry/failover reason, timestamps, budget settlement, or mutable execution metadata. Finalization performs a fresh authoritative organization + run + lease/fence check. Reclaim is durable and lock-safe, does not preempt a legitimate absolute execution window, preserves attempt identity/provenance, and reconciles uncertain external work conservatively.

## Immutable execution snapshots
Asynchronous or delayed execution snapshots every mutable value that affects correctness, cost, or security before the side effect: prompt/model release, pricing, credential revision, provider/configuration digest, embedding/RAG configuration, template, or similar version. Later execution uses the snapshot or verifies exact compatibility and otherwise fails closed. Settlement and provenance use the same snapshot that justified reservation/execution.

## Cost and provider safety
Before a potentially billable call reserve a conservative local upper bound for every permitted meter, including embedding/tool/provider-request dimensions. Unknown or unsupported meters fail closed instead of becoming zero. Provider-reported usage is settlement evidence, not a substitute for pre-call reservation. Organization limits may tighten, never expand, the platform ceiling. Health is valid only for the exact tested provider, credential revision, and safe configuration digest; rotation or configuration changes invalidate it. Unsupported or non-canonical probes fail closed.

## Tenant, protected-data, and production fail-closed boundaries
`organization_id` is a security boundary: derive it server-side, scope every read/write/job reference, validate referenced ownership, and add composite tenant FKs when relational integrity requires them. Generic logs, errors, audit metadata, and AI provenance contain only safe structured metadata, digests, and references; protected traces remain encrypted and separately authorized. Evaluation fixtures are synthetic or explicitly approved non-protected data. Production versioned execution fails closed when the required prompt, model, template, rule, or content version is missing or unusable; never invent hidden business fallback text.

## RAG is one security and cost boundary
Initial and model-triggered retrieval use the same organization predicate, allowed-scope authorization, bounded query/top-K/result limits, immutable embedding compatibility, threshold policy, budget reservation, and safe source/revision/chunk/configuration provenance. Retrieved instructions, URLs, code, templates, and prompt-like text are inert data; model input may narrow retrieval policy but never expand it. Historical provenance survives normal content retirement according to the retention contract and never stores plaintext chunks in generic AI records.

## Bounded migrations, control-plane reads, and version allocation
Migrations must be expand/contract compatible; large backfills and index work must be bounded or separated from the deploy transaction. Admin, evaluation, monitoring, and dashboard paths are application-bounded and paginated, not merely UI-limited; do not decrypt or perform external I/O per rendered row. Actor/initiator identity comes from the authorized server boundary, not an untrusted queue or request field. Concurrent activation/version numbering uses a PostgreSQL lock or sequence plus a unique constraint; `max + 1` outside the lock is not safe. Unique-constraint races are an expected concurrency path and must converge to the durable winner. Retirement, deletion, and reparenting must preserve historical provenance or explicitly invalidate it; do not silently cascade away records required to explain a completed execution.

## Reuse proven primitives and review proportionally
Before adding claim, reclaim, lease, retry, bounded PostgreSQL, activation, snapshot, or budget semantics, search existing milestone implementations and tests and follow the proven primitive. Reopen an accepted area only for a regression, new execution path, invariant contradiction, or newly verified framework/provider behavior; distinguish a new correctness/security bug from a stylistic preference. Ordinary Filament CRUD/UI does not require high-risk concurrency ceremony unless it crosses a tenant, medical, payment, migration, queue ownership, AI safety/budget, or protected-trace boundary.
