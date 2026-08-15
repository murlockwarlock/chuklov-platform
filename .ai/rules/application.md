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
