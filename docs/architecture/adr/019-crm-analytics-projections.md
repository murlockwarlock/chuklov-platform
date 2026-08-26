# ADR-019: Read-Only CRM Analytics Projections

- Status: Accepted
- Date: 2026-08-27

## Context

REQ-CRM-002 requires one operational CRM dashboard to report across Identity, Attribution, Scheduling, Finance, AI, and Knowledge records. Those modules already own the authoritative lifecycle data, while analytics needs bounded period-filtered aggregates and must preserve organization isolation and least-privilege access.

## Decision

1. Analytics is an Application-only projection module. Its projections query authoritative organization-scoped source tables directly with SQL aggregates and do not own lifecycle records.
2. The existing Filament `Инфопанель` composes the projections into permission-specific widgets. A shared Filament page filter supplies organization-local calendar dates converted to UTC half-open instants; the existing upcoming-bookings widget remains independent.
3. Finance projections consume validated base-currency obligation and ledger snapshots and reuse the existing reconciliation authority. Invalid financial configuration or reconciliation fails closed for analytics.
4. Historical realized LTV and operational rebooking retention remain explicitly labeled definitions. No predictive model, warehouse, ETL, cache of business truth, external analytics service, or sensitive payload projection is introduced.

## Consequences

- Dashboard values reconcile directly to current authoritative records without duplicated analytics truth or periodic synchronization.
- Aggregate query count and memory usage remain independent of client, booking, and ledger row counts; reporting indexes are added only when query evidence justifies them.
- Every widget has a narrow existing organization permission, and unauthorized financial, AI, or Knowledge data is not included in another widget's payload.
- PostgreSQL remains the authoritative verification target; the repository's local SQLite test configuration can execute focused behavior tests but cannot claim PostgreSQL integration success.
