---
name: durable-workflows
description: Design and review Chuklov persisted, replayable, delayed, queued, scheduled, webhook/callback, provider-side-effect, and other asynchronous workflows. Use when work crosses a persistence or external-input boundary, is materialized for later execution, must be idempotent or auditable, has competing writers, or needs durable retry, fallback, terminal-state, authorization-revalidation, or time-zone behavior.
---

# Durable Workflows

## Design before implementation

1. Confirm the milestone and read the affected `REQ-*`, architecture, `.ai/rules/application.md`, domain skill, state model, and nearest tests.
2. Inventory every persistence and external-input boundary. Validate runtime type and shape before constructing strict Domain values; keep malformed data outside Domain types and add safe PostgreSQL constraints when the database can enforce the invariant.
3. Resolve organization ownership server-side. Identify mutable permission, membership, feature, entitlement, consent, identity, or availability state that must be revalidated when delayed work executes.
4. Decide which semantics must remain historically stable. Persist an immutable revision reference or explicit snapshot; permit mutable live-stop controls only when intentional and documented.
5. Persist instants as canonical UTC instants in PostgreSQL `TIMESTAMPTZ`. Define source and presentation IANA zones plus any business wall-clock or date interpretation.

## Define the durable protocol

1. Put the business mutation and every required audit event, domain event, outbox record, scenario event, or ledger entry in one transaction.
2. Specify the stable idempotency key, payload identity where applicable, replay result, concurrent-duplicate behavior, and exact external side-effect boundary before mutable checks.
3. Model every durable state and transition, including claim, retry, fallback, suppression, exhaustion, uncertainty, and terminal completion. A no-send exception loop or permanently stale `Processing` row is not fail-closed.
4. Define recovery for worker death both before the side effect and after an external side effect but before outcome persistence. Preserve uncertainty when delivery cannot be proven, rather than guessing success or retrying unsafely.
5. Enforce competing-writer invariants with PostgreSQL locks or constraints and prove races with process-level PostgreSQL tests.
6. Keep UI and transport adapters thin. Application actions independently derive trusted immutable fields, authorize, enforce feature or entitlement gates, and validate Domain input.

## Select adverse coverage

Choose only applicable cases. For a risky persisted or asynchronous workflow, state which cases were selected and explain material omissions at handoff.

| Concern | Consider |
| --- | --- |
| Boundary and isolation | Malformed, null, or scalar persisted values; cross-organization access. |
| Execution authority | Permission or membership revoked after materialization; disabled feature or entitlement through a direct Application, API, or job path. |
| Replay and races | Duplicate request or replay; concurrent same-key execution; competing writers with process-level PostgreSQL coverage. |
| Crash and recovery | Worker death before the side effect; worker death after an external side effect but before outcome persistence; retry exhaustion; fallback transition; scheduler rediscovery. |
| Historical semantics and time | Configuration or version changes after materialization; relevant DST gaps, overlaps, date boundaries, and large timezone offsets. |
| Durable outcome | Explicit terminal or recoverable state after every failure path, plus behavior of the next scheduler or replay cycle. |

## Completion check

1. Assert durable database state, emitted evidence, external side-effect counts, and the next replay or scheduler result; do not stop at asserting an exception or no send.
2. Use real PostgreSQL for JSON/JSONB shape, locking, constraints, TIMESTAMPTZ, and race behavior that SQLite cannot prove.
3. Run focused tests, affected module tests, and applicable repository gates. Report only executed results and the selected adverse cases or justified omissions.
