# ADR-016: Finance and Multi-Currency Core

- Status: Accepted
- Date: 2026-08-15

## Context

The platform needs organization-scoped prices, completed-visit receivables, manual and partial payments, historical multi-currency values, and a provider-neutral payment boundary without introducing a real payment provider in Phase 1. Current rates and editable Service records cannot be the source of historical financial truth.

## Decision

- Persist authoritative money as integer minor units paired with a controlled currency code. Currency scale and deterministic rounding come from one catalogue; Brick Math performs exact parsing, arithmetic, conversion, and overflow checks.
- Keep base, display, payment, and settlement currencies explicit on financial records. Store manual FX rates as `NUMERIC(38,18)` in the direction `1 source = rate target`, and snapshot the rate identity, version, effective time, source/target amounts, scales, and rounding mode wherever conversion is used.
- Create one immutable organization-scoped financial obligation for a priced Booking when the existing completion action reaches `COMPLETED`. Fixed Service prices in an enabled currency win over conversion. Booking status and financial status remain separate.
- Treat the financial ledger as append-only truth. Manual and fake-gateway settlements append entries; corrections append compensating entries that reference the original. Balance, debt, and status are derived by reconciliation, and row locks/database constraints protect concurrent application.
- Define `PaymentGateway` as a provider-neutral boundary for server-calculated initiation, trusted normalized settlement evidence, verified provider events, idempotency/deduplication, and reconciliation. M6 implements only a deterministic fake adapter; real providers remain REQ-PAYMENT-006/M13 scope.
- Publish debt facts through the existing transactional Scenario Engine. Finance supplies only a safe typed event and outstanding-debt condition; M5 delivery, timing, templates, channels, and execution idempotency remain the single reminder path.

## Consequences

Historical financial values remain explainable after settings or Service prices change, and no frontend-formatted amount can become authoritative. Overpayment, cross-organization references, duplicate commands, and stale provider events fail closed or replay the original result. The system has no wallet, credit, refund, tax, provider settlement, or real checkout behavior until separately confirmed.
