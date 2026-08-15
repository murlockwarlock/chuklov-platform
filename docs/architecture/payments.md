# Payments and Pricing

Money is non-float, currency-explicit, and deterministically rounded. Payment ledger is auditable truth; booking and payment states are independent. Gateways are ports with server-side amount/status verification, webhook authenticity, idempotency, and reconciliation.

See REQ-CURRENCY-*, REQ-PAYMENT-*, ADR-010, OQ-005.

M6 persists authoritative amounts as signed PostgreSQL `bigint` minor units and uses the centralized currency catalogue for scale. Brick Math performs parsing, arithmetic, conversion, overflow checks, and explicit half-up/half-even rounding; formatted strings are presentation only. Manual rates are PostgreSQL `NUMERIC(38,18)` values with direction `1 source currency = rate target currency`. Obligations and ledger conversions persist source/target values, rate text, rate identity/version, scales, and rounding mode so later rate edits cannot recalculate history.

The `Finance` module keeps base, display, payment, and settlement roles explicit. A priced completed Booking creates one immutable organization-scoped financial obligation; the current Service price is not consulted afterward. The immutable ledger accepts manual or fake-gateway settlement entries and compensating corrections only. Outstanding amount and status are reconciled from the obligation and signed entries, with row locks and database constraints rejecting overpayment and protecting concurrent settlement.

`PaymentGateway` is provider-neutral. M6 binds only a deterministic fake adapter whose trusted evidence passes through the same application settlement boundary required for a future verified provider event. Durable idempotency covers obligation creation, manual payments, corrections, gateway initiation/settlement, and provider-event deduplication. M6 does not implement a real provider; REQ-PAYMENT-006 and OQ-005 remain M13 scope.
