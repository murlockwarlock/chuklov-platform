---
paths:
  - '{app/Modules/**,app/Jobs/**,database/migrations/**,tests/Integration/**}'
---

# Integration

## Durable claims, leases, deadlines, and PostgreSQL session state
Treat PostgreSQL as the concurrency authority. Establish durable unique/ownership claims before external I/O, commit before provider/network calls, and finalize with a fresh lease/fence check. Share one absolute deadline across substeps and preserve caller-owned PostgreSQL settings across nested/savepoint calls; prove locks, session state, retries, and races with real PostgreSQL tests.

## PostgreSQL evidence is mandatory for PostgreSQL invariants
`PASS` means the test was executed and passed. `EXISTS — NOT RUN LOCALLY` means the test exists but was not executed locally; never report it as PASS. SQLite is not evidence for locks, `SKIP LOCKED`, transaction isolation, savepoints, `SET LOCAL`/session state, advisory locks, PostgreSQL constraints/extensions, pgvector, or process races. Hosted CI is authoritative for these checks under the repository's no-heavy-local-infrastructure policy.

## Required session-state regression
Keep a real PostgreSQL integration test for: outer transaction → caller establishes setting X → bounded helper succeeds → caller setting remains X → unrelated SQL still observes X. Cover helper calls outside a transaction and, where the implementation supports them, nested Laravel savepoints and failure paths. Use rollback/savepoint semantics before cleanup SQL after an aborted statement.

## Concurrency test checklist
For idempotency, queues, workers, leases, reclaim, budgets, activation/version numbering, delivery attempts, external side effects, or transaction/session state, record answers to:

- What is the durable uniqueness or ownership boundary?
- Which row is locked, and can two transactions both pass the precondition?
- Does the durable claim commit before the external call?
- What happens after worker death before and after the side effect?
- What happens when ownership changes while the call is in flight?
- Can a stale owner write after returning?
- Can retry repeat a billable or non-idempotent side effect?
- Does rollback restore caller transaction-local/session state?
- Is SQLite materially different, and is there a real PostgreSQL test?

Use independent PostgreSQL connections/processes when reproducing races. Assert durable state, external-call count, provenance/attempt identity, budget settlement, and the next reclaim/replay result—not only an exception or return value.

## Fixture and control-plane discipline
Integration tests create the complete versioned/configuration fixture they require, use organization-unique data, and restore mutable config/fakes between tests. They must not depend on test order, stale global configuration, or a prior active prompt/model/release. PostgreSQL-only tests may be skipped on SQLite, but a skip is not acceptance evidence. CI sharding is a feedback optimization, not permission to omit the authoritative integration scope.
