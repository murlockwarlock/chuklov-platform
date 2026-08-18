# Concurrency and External Effects

This is the reusable platform protocol for persisted work that crosses a PostgreSQL, queue, worker, provider, storage, or other external boundary. It generalizes the proven Scenario, Knowledge, and AI patterns; it does not create a new runtime abstraction.

## Protocol

1. Resolve and authorize the server-derived organization and actor.
2. Validate the input and materialize immutable versions/configuration required for historical correctness, cost, and security.
3. In a short PostgreSQL transaction, lock the authoritative row where needed and create the durable unique/ownership claim, reservation, attempt, or lease. Catch a unique-constraint race by loading the durable winner and returning its replay result.
4. Commit before provider, embedding, Telegram, storage, notification, payment, webhook, or other unbounded I/O. If queue dispatch is initiated inside a transaction, use explicit after-commit semantics.
5. Execute with one immutable absolute deadline. Pass only remaining time to every provider/tool/database operation and bound or disable invisible transport retries.
6. Finalize in a short transaction with a fresh organization, row-state, and lease/fence check. Persist only the result authorized by the current owner; preserve uncertainty when an external side effect may have happened but cannot be proven.
7. Reclaim only after the legitimate worker lease/window expires. Use PostgreSQL locking such as `FOR UPDATE SKIP LOCKED` where appropriate, preserve attempt identity and provenance, and make reconciliation idempotent.

## PostgreSQL state safety

Laravel nested `DB::transaction()` calls may be savepoints on one PostgreSQL transaction. A helper that changes `statement_timeout`, `lock_timeout`, `SET LOCAL`, `set_config`, `search_path`, role/session settings, advisory state, or isolation must not assume that a nested call owns an independent transaction. Successful bounded work must restore caller-owned effective state when required. On a failed PostgreSQL statement, rollback the failed savepoint/transaction before issuing cleanup SQL; an aborted subtransaction cannot safely run arbitrary cleanup statements. A reduced timeout must never leak into unrelated caller SQL.

The permanent regression shape is:

```text
outer transaction
  establish caller state X
  bounded helper succeeds
  assert caller state is X
  assert unrelated SQL observes X
commit/rollback
```

This is PostgreSQL-only evidence. SQLite cannot validate it.

## Ownership, snapshots, and settlement

A lease token is a conditional write capability. A stale worker cannot write terminal/attempt status, output, tool or delivery provenance, retry/failover reason, timestamps, budget settlement, or mutable execution metadata. External work already performed by a stale owner may need ownership-independent conservative reconciliation, but the stale owner must not claim success.

Queued and delayed work snapshots prompt/model/template versions, provider credential revision and safe configuration digest, embedding/RAG configuration, pricing, and any other mutable execution determinant before the side effect. Use that snapshot for execution, settlement, and provenance; otherwise fail closed rather than silently using newer active configuration.

The pre-call reservation covers every locally permitted billable meter and all locally permitted provider/tool steps. Unknown meters, incomplete pricing, unsupported provider combinations, and unbounded retries fail closed. Actual provider usage improves settlement evidence but does not replace the local upper bound. A provider health flag is valid only for the exact tested provider/credential/configuration tuple and is invalidated by rotation or configuration mutation.

## Tenant and protected-data boundaries

`organization_id` is the ownership and security boundary. Every referenced record is resolved and authorized within it; migrations use composite tenant FKs where a relation needs relational enforcement. Queue payloads carry identifiers and safe context, not protected free text. Generic logs, audit records, and provenance contain safe metadata, digests, and references only. Protected prompts, model responses, tool payloads, raw RAG chunks, medical narrative, and secrets remain encrypted/separately authorized. Production versioned execution fails closed when approved configuration is absent; no hidden generic business fallback is allowed.

## Review and test checklist

Before changing a workflow, inspect existing claim/lease/reclaim/snapshot/budget/timeout implementations and answer:

- What is the durable uniqueness boundary and which row is locked?
- Can two transactions pass the same precondition, and what unique constraint resolves it?
- Is every external call after the durable claim commit?
- What happens if the worker dies before or after the side effect?
- What happens if ownership changes while external work is in flight?
- Can a stale owner write after it returns?
- Can a retry repeat a billable or non-idempotent side effect?
- Does rollback restore caller transaction/session state?
- Is mutable configuration snapshotted and compatible at execution time?
- Are migration, evaluation, monitoring, and dashboard reads bounded at the Application boundary?
- Is the actor/initiator server-derived and are tenant FKs/lifecycle semantics explicit?
- Is SQLite materially different, and did a real PostgreSQL test execute?

Ordinary Filament CRUD and layout work remains proportionally reviewed. Apply the high-risk gate when the path crosses tenant isolation, medical encryption, migrations/data loss, payments, concurrency, queue ownership, provider safety/budget, or protected trace access.
