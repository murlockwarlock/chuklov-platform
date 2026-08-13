# Requirements Changelog

## 2026-08-13 — M4B owner decision and implementation traceability

- Resolved OQ-009 as an explicit organization-scoped many-to-many Specialist-Service assignment. Assignments are required for availability and booking creation, managed by authorized CRM staff, tenant-safe, and removable without changing historical bookings.
- Clarified REQ-BOOKING-002/003: unassigned pairs have no client availability; HOME_VISIT `PENDING_REVIEW` is non-blocking and approval must recheck the preferred interval transactionally before it becomes blocking.

## 2026-08-12 — M3 owner clarification

- Added `REQ-SPECIALIST-001`: one organization-owned Specialist/Practitioner entity with display/full name, active state, optional same-organization staff User link, optional IANA timezone, audited management, and no implied schedule or service assignment.
- Added `REQ-NOTIFY-007` for future organization-configured internal notification recipients and verified delivery-channel selection by event type; implementation remains in Milestone 5.
- Added `REQ-MEDICAL-SEC-001` for a future application encryption boundary supporting organization-scoped key resolution and key rotation; implementation remains in Milestone 7.
- Added OQ-009 to defer any specialist-to-service relationship decision to Milestone 4 scheduling.
- Clarified that specialist staff links are proven by active same-organization `OrganizationMembership` rows; legacy `users.organization_id` is not an authorization or membership source.

## 2026-08-13 — M3 implementation traceability

- Marked `REQ-CRM-001`, `REQ-CLIENT-003`, `REQ-SPECIALIST-001`, `REQ-SERVICE-002`, `REQ-PRODUCT-001`, and `REQ-CHANNEL-004` implemented after the organization-scoped CRM, catalog, specialist, restriction, and managed-content slices were added with focused and PostgreSQL coverage.

## 2026-08-12 — M2 owner decisions

- Resolved OQ-001 as passwordless ordinary-browser email authentication with short-lived single-use bounded verification codes, Laravel session regeneration, provider-neutral delivery, and no reusable passwords or SMS OTP.
- Resolved OQ-006 as versioned organization-scoped legal documents and exact published-version consent evidence. Legal wording remains managed content; Phase 1 is platform-controlled and an organization cannot self-enable organization-managed wording.
- Added M2 traceability for verified email/Telegram identity boundaries, future provider-neutral email communication readiness, canonical timezone-aware instants/IANA timezone context, and centralized portal date/time presentation.

## 2026-08-12 — Normalized baseline

- Created stable executable `REQ-*` records from client v2.2, the client changelog, owner-confirmed decisions, non-superseded historical confirmations, and architecture constraints.
- Preserved historical details for progressive profiling, configurable cancellation cutoff, managed static content, broadcasts, abandoned-flow re-engagement, multi-test/stagnation behavior, booking block, home-visit payment choice, and feature readiness.
- Explicitly excluded superseded technical suggestions such as Laravel 12, `master_id` tenancy, Celery, mandatory Phase 1 S3, combined booking/payment status, raw DICOM, and premature microservices.
- Recorded unresolved product behavior in `open-questions.md` without guessing.
