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
| OQ-010 | REQ-SCHEDULING-001 | When a schedule/configuration mutation conflicts with future bookings, must the admin mutation be blocked until each booking is explicitly resolved, or may authorized staff save with a warning/exception? Define the required warning, affected-booking selection, and lifecycle action. | M4/M4C schedule mutation workflows |
| OQ-011 | REQ-BOOKING-011 | Is client `NO_SHOW` a dedicated BookingStatus, an immutable booking event/outcome, or both? Confirm whether any additional declined-request representation is needed beyond the existing typed HOME_VISIT rejection, without deciding payment/refund consequences. | M4 final lifecycle acceptance |
| OQ-012 | REQ-BOOKING-001, REQ-BOOKING-005 | Does Phase 1 require more than one managed office/service location with explicit address, timezone, capacity, and Specialist availability, or is one organization-level office sufficient? Do not add a Location aggregate until this is answered. | M4/M4C location and home-visit planning |
| OQ-013 | REQ-CLIENT-005 | Confirm jurisdiction, legal basis, retention schedules, consent-withdrawal consequences, deletion/anonymization rules, and records that must be preserved for the client data lifecycle. | M15/M16 legal and security production readiness; earlier support only where M7 medical data requires it |

No open question blocks Milestone 0.

## Resolved M2 decisions

### OQ-001 — RESOLVED 2026-08-12

Phase 1 ordinary browser authentication is passwordless email authentication with a short-lived, single-use one-time verification code. Email is normalized consistently; codes are hashed, expire, have bounded attempts and request rate limits, do not use reusable passwords or SMS/phone OTP, and regenerate the Laravel session after success. Email delivery is provider-neutral. Telegram authentication and channel connection remain separate verified identity flows.

### OQ-006 — RESOLVED 2026-08-12

Legal wording is never invented or hardcoded by the application. M2 provides organization-scoped, locale-aware, versioned legal documents with draft/published/archived lifecycle, immutable published content, exact-version consent evidence, and a platform-controlled Phase 1 `PLATFORM_MANAGED` mode. Organizations cannot self-enable `ORGANIZATION_MANAGED`; future authorized organization-managed wording remains a separately scopeable Phase 2 capability. The organization/platform/legal owner supplies the actual legal text, jurisdiction, lawful basis, retention, and publication decisions through the managed records.

### OQ-009 — RESOLVED 2026-08-13

Specialist-to-Service eligibility is an explicit organization-scoped many-to-many relationship. One Specialist may be assigned to many Services and one Service may be assigned to many Specialists. Assignments are managed only by authorized CRM staff, require same-organization ownership, and are required for availability and booking creation. Inactive Specialists or Services remain non-bookable. Removing an assignment prevents future bookings for that pair without deleting or changing historical bookings. No skills, qualifications, commissions, price overrides, specialist-specific durations, inventory, or other unconfirmed behavior is implied.
