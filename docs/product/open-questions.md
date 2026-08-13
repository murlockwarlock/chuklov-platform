# Open Questions

Questions block only their dependent work.

| ID | Affects | Question | Needed before |
|---|---|---|---|
| OQ-002 | REQ-BOOKING-007 | Confirm cancellation/reschedule cutoff, admin exception flow, and payment/refund consequences. Is 24 hours the initial configured value? | Milestone 4 policies |
| OQ-003 | REQ-PRODUCT-002 | Does Phase 1 product selling require cart, quantity, inventory, variants/SKU, delivery/fulfilment, and refunds? | Product commerce implementation |
| OQ-004 | REQ-SUBSCRIPTION-002 | Confirm manual vs recurring billing, auto-renew, cancellation, failure, grace period, and entitlement end. | Milestone 12 billing lifecycle |
| OQ-005 | REQ-PAYMENT-006 | Which real payment providers, merchant entities, currencies, refund capabilities, and rollout order are contracted? | Milestone 13 adapters |
| OQ-007 | REQ-REFERRAL-001 | Confirm bonus earning, redemption, expiry, refund reversal, and cash-out rules. | Milestone 11 ledger |
| OQ-008 | REQ-RAG-001 | Confirm which method materials may be platform-shared versus organization-only. | Milestone 9 ingestion |

No open question blocks Milestone 0.

## Resolved M2 decisions

### OQ-001 — RESOLVED 2026-08-12

Phase 1 ordinary browser authentication is passwordless email authentication with a short-lived, single-use one-time verification code. Email is normalized consistently; codes are hashed, expire, have bounded attempts and request rate limits, do not use reusable passwords or SMS/phone OTP, and regenerate the Laravel session after success. Email delivery is provider-neutral. Telegram authentication and channel connection remain separate verified identity flows.

### OQ-006 — RESOLVED 2026-08-12

Legal wording is never invented or hardcoded by the application. M2 provides organization-scoped, locale-aware, versioned legal documents with draft/published/archived lifecycle, immutable published content, exact-version consent evidence, and a platform-controlled Phase 1 `PLATFORM_MANAGED` mode. Organizations cannot self-enable `ORGANIZATION_MANAGED`; future authorized organization-managed wording remains a separately scopeable Phase 2 capability. The organization/platform/legal owner supplies the actual legal text, jurisdiction, lawful basis, retention, and publication decisions through the managed records.

### OQ-009 — RESOLVED 2026-08-13

Specialist-to-Service eligibility is an explicit organization-scoped many-to-many relationship. One Specialist may be assigned to many Services and one Service may be assigned to many Specialists. Assignments are managed only by authorized CRM staff, require same-organization ownership, and are required for availability and booking creation. Inactive Specialists or Services remain non-bookable. Removing an assignment prevents future bookings for that pair without deleting or changing historical bookings. No skills, qualifications, commissions, price overrides, specialist-specific durations, inventory, or other unconfirmed behavior is implied.
