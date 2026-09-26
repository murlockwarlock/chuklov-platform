# ADR-024: Referral Reward Ledger and Manual Partner Payouts

- Status: Accepted as an architectural decision
- Date: 2026-09-05

## Context

The product-neutral M11A referral relationship and Finance settlement evidence are authoritative boundaries, while the owner now requires configurable referral rewards and manual partner payout requests. Reward economics must be organization-scoped, historical rewards must remain explainable after configuration changes, and concurrent settlement or withdrawal retries must not create duplicate rewards or reserve more than the available balance.

## Decision

1. Referrals owns a small internal append-only reward ledger. It uses the existing Finance Money, currency catalogue, settlement reconciliation, organization context, authorization, audit, and PostgreSQL concurrency patterns. It does not add a wallet package, mutable authoritative balance, or a second Finance source of truth.
2. Each organization has an effective-dated immutable reward-program version. A version snapshots enabled state, qualification rule, formula, currency, amount/percentage, rounding mode, and effective time. Qualification selects the version effective for the authoritative settlement observation, so later edits do not reinterpret history.
3. Qualification requires the same-organization authoritative ReferralRelationship, matching referred Client, and authoritative Finance settlement/commercial evidence. Organic source text, channel similarity, and marketing attribution are not qualification evidence. A settlement observed without a relationship is not rewritten or awarded retroactively by this slice.
4. Earned and reversed entries are append-only and retain organization, beneficiary, referred Client, relationship, Finance evidence, program version, amount, currency, idempotency identity, and reversal provenance. PartnerCash balances remain projections grouped by currency. Ordinary Client ServiceCredit is normalized to the organization's Base Currency and never uses an implicit 1:1 cross-currency substitution.
5. A partner payout request reserves one currency and amount immediately against the projected available balance. Its request, approval, rejection, cancellation, and paid transitions are audited; request events are append-only. Rejection and cancellation release the reservation, while paid keeps it deducted. Marking paid records the administrator and optional external payment note/reference and means only that the administrator confirmed payment outside the system.
6. PostgreSQL uniqueness, checks, immutable-row triggers, transactions, and beneficiary row locks are the correctness boundary for duplicate settlement qualification, duplicate reversals, concurrent reservations, and retry-safe payout transitions. A future provider adapter may replace only the approved-to-payment-execution step.

### 2026-09-26 owner amendment

Ordinary Client ServiceCredit is accounted for in the current Organization Base Currency. Fixed and percentage ServiceCredit rewards are normalized through the existing Finance currency configuration, and each earning keeps immutable source/target/rate evidence. Redemption may target an obligation's settlement currency only when the configured rate exists; the resulting base debit and target settlement amount are recorded together. Restore uses that historical base debit and does not revalue it at a later rate. These rules do not change PartnerCash balances or payout semantics.

## Consequences

- Reward rules can be changed without changing historical ledger entries, and disabling a program affects only future qualification.
- Partners see accrued, reserved, paid, and available totals separately per currency, with no cash-out promise or automated payout behavior.
- Existing referral identity/relationship and Finance settlement architecture remains authoritative and is not rebuilt.
- A future refund or settlement-reversal event can append a compensating reversal through the same ledger boundary; until such an authoritative event exists, manual CRM reversal is the bounded operation and no provider refund detection is inferred.
