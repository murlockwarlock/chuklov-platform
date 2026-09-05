# Open Questions

Questions block only their dependent work.

| ID | Affects | Question | Needed before |
|---|---|---|---|
| OQ-003 | REQ-PRODUCT-002 | Does Phase 1 product selling require cart, quantity, inventory, variants/SKU, delivery/fulfilment, and refunds? | Product commerce implementation |
| OQ-004 | REQ-SUBSCRIPTION-002 | Confirm manual vs recurring billing, auto-renew, cancellation, failure, grace period, and entitlement end. | Milestone 12 billing lifecycle |
| OQ-005 | REQ-PAYMENT-006 | Which real payment providers, merchant entities, currencies, refund capabilities, and rollout order are contracted? | Milestone 13 adapters |
| OQ-008 | REQ-RAG-001 | Confirm which method materials may be platform-shared versus organization-only. | Milestone 9 ingestion |
| OQ-013 | REQ-CLIENT-005 | Confirm jurisdiction, legal basis, retention schedules, consent-withdrawal consequences, deletion/anonymization rules, and records that must be preserved for the client data lifecycle. | M15/M16 legal and security production readiness; earlier support only where M7 medical data requires it |
| OQ-014 | REQ-NOTIFY-004 | Confirm the business/clinical condition intended by the conditional +72h post-session follow-up. M5B seeds only the supported neutral `booking.status = completed` guard until this is confirmed. | Owner decision before changing the +72h rule condition; generic scenario capability is unblocked |
| OQ-015 | REQ-SURVEY-002 | Provide the approved full “9 systems” and MSQ questionnaire sources, including exact questions, answer options, scoring rules, thresholds, tags, result text, locale variants, and provenance/licensing constraints. The local v2.2 specification says long questionnaire texts were moved to separate documents, but those documents are absent. | Importing/activating the required initial definitions and closing M8; the generic engine remains unblocked |

No open question blocks Milestone 0.

## Resolved M11 decisions

### OQ-007 — RESOLVED 2026-09-05

Phase 1 uses an organization-scoped referral reward program that is disabled by default. An authorized CRM administrator can create effective-dated immutable versions selecting first or every authoritative settled Finance payment and a fixed amount in an explicit currency or a percentage of the settled amount. Percentage calculation uses the existing integer minor-unit Money and deterministic rounding conventions; fixed and percentage rewards preserve their applied version and currency.

Only an authoritative same-organization ReferralRelationship, the matching referred Client, and authoritative Finance settlement/commercial evidence can qualify a reward. Organic source text, Telegram username, phone/name similarity, and marketing attribution alone never qualify. Reward history is an append-only earned/reversed ledger with derived per-currency balances. Partners may request manual payout reservations one currency at a time; CRM may approve, reject, cancel, or mark a request paid after an external payment, with all transitions audited. There is no automated payment-provider payout, redemption, expiry, MLM, cross-currency aggregation, or cross-tenant qualification.

## Resolved M2 decisions

### OQ-001 — RESOLVED 2026-08-12

Phase 1 ordinary browser authentication offers Telegram and passwordless email as independent methods. Telegram web login uses a short-lived, single-use, browser-bound deep-link handshake and authentic bot evidence; a verified identity resolves an existing organization-scoped Client or creates one without matching by name or username. Mini App authentication continues to use verified fresh non-replayed initData. Email codes remain normalized, hashed, expiring, bounded, single-use, provider-neutral, and free of reusable passwords or SMS OTP. Every successful method regenerates the session.

### OQ-006 — RESOLVED 2026-08-12

Legal wording is never invented or hardcoded by the application. M2 provides organization-scoped, locale-aware, versioned legal documents with draft/published/archived lifecycle, immutable published content, exact-version consent evidence, and a platform-controlled Phase 1 `PLATFORM_MANAGED` mode. Organizations cannot self-enable `ORGANIZATION_MANAGED`; future authorized organization-managed wording remains a separately scopeable Phase 2 capability. The organization/platform/legal owner supplies the actual legal text, jurisdiction, lawful basis, retention, and publication decisions through the managed records.

### OQ-009 — RESOLVED 2026-08-13

Specialist-to-Service eligibility is an explicit organization-scoped many-to-many relationship. One Specialist may be assigned to many Services and one Service may be assigned to many Specialists. Assignments are managed only by authorized CRM staff, require same-organization ownership, and are required for availability and booking creation. Inactive Specialists or Services remain non-bookable. Removing an assignment prevents future bookings for that pair without deleting or changing historical bookings. No skills, qualifications, commissions, price overrides, specialist-specific durations, inventory, or other unconfirmed behavior is implied.

## Resolved M4 decisions

### OQ-002 — RESOLVED 2026-08-13

Self-service cancellation and rescheduling use an organization-configurable cutoff. The Phase 1 default is 1440 minutes and is configuration, not a booking-logic constant. Clients may change eligible non-terminal bookings at or beyond the cutoff; inside it they are directed to staff. Authorized CRM staff may override inside the cutoff only with an explicit reason. Pending-review HOME_VISIT requests may be withdrawn by their owning client without the confirmed-booking cutoff. Rejected, cancelled, completed, and no-show bookings are terminal. Cancellation/rescheduling changes booking state only; M4 never invents fees, credits, refunds, forfeitures, or payment-state changes. Rescheduling preserves Booking identity and calendar_uid, increments the event/version sequence, records immutable history, rechecks authoritative availability transactionally, and preserves the old time if the target loses a race.

### OQ-010 — RESOLVED 2026-08-13

Scheduling/configuration mutations never silently rewrite, cancel, delete, or move existing bookings. Before a relevant mutation, M4 calculates future non-terminal impact and gives CRM an explicit warning and affected-booking summary. An impacted mutation requires explicit staff acknowledgement, but existing bookings retain their identifiers, time, format, status, and history. Affected records are discoverable as needing scheduling attention and are resolved later through normal booking lifecycle actions. The acknowledgement and impact count are audited within the organization boundary.

### OQ-011 — RESOLVED 2026-08-13

NO_SHOW is a dedicated typed terminal BookingStatus. Only authorized staff may apply it after the scheduled start to an expected/requested or confirmed booking. The transition records an immutable BookingEvent and allowlisted audit metadata. It has no automatic payment, refund, fee, or debt consequence in M4. REJECTED remains a separate terminal request outcome.

### OQ-012 — RESOLVED / SUPERSEDED 2026-09-04

The 2026-08-13 single-office decision is superseded by the Phase 1 owner decision to support multiple organization-scoped working locations. The bounded model stores human name, address, IANA timezone, optional map data, active state, and one active default office; it does not introduce rooms, route optimization, multi-site capacity, or practitioner-location assignments. OFFICE bookings select and snapshot a working location, while HOME_VISIT destinations remain booking-specific and location-day rules remain explicit configuration.
