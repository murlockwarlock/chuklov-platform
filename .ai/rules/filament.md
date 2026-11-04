---
paths:
  - 'app/Filament/**'
---

# Filament-specific constraints

The canonical CRM language and first-time usability gate are in
`.ai/rules/product-ui.md`. This file covers only Filament implementation
constraints.

## Bound per-row work

Identify work executed per rendered row. Do not hide database queries,
external I/O, expensive decryption, or repeated business calculations inside
per-row callbacks such as `visible()`, `disabled()`, badge/state formatters,
authorization callbacks, or `getStateUsing()` unless the design proves
bounded, preloaded, request-scoped behavior. Do not weaken Application or
Policy authorization to improve query count. Prefer explicit projections,
preloading, and request-scoped reuse; do not eager-load an entire relationship
graph merely to reduce query count.

## Control-plane bounds

Evaluation, monitoring, provider, run, and dashboard tables use
Application-enforced pagination, bounded filters, bounded detail payloads, and
safe projections. UI limits alone are not protection against an unbounded
query or protected-data decryption.

Ordinary CRUD, navigation, copy, and responsive layout do not by themselves
require concurrency review. Escalate when the screen crosses tenant
ownership, medical/protected trace access, payment, migration, queue
ownership, or AI safety/budget boundaries.
