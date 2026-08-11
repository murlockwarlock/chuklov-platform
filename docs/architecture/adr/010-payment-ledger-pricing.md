# ADR-010: Payment Ledger and Pricing Snapshots

- Status: Accepted
- Date: 2026-08-12

## Context

Partial/manual payments, debt, multiple currencies, and historical reporting require auditability.

## Decision

Use an immutable payment ledger, non-float Money, explicit currency roles, and exchange/rounding snapshots. Booking and payment lifecycles are separate.

## Consequences

Balances are derived/reconcilable. Gateways confirm server-side and require idempotent verified webhooks.
