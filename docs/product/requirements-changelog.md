# Requirements Changelog

## 2026-08-15 — M5B scenario-family implementation traceability

- Implemented `REQ-NOTIFY-001`–`REQ-NOTIFY-007` on the accepted M5A engine: CRM-configured delays and bounded repeats, typed `booking.completed`/`onboarding.started` rules, immutable action snapshots, idempotent sequence materialization and delivery, RU/EN version-pinned templates, consent-aware onboarding re-engagement boundaries, configured no-next-booking retention evaluation, and organization-scoped internal member/role recipients with verified-channel revalidation.
- Seeded organization-safe editable RU/EN post-session +24h, +48h, and conditional +72h rules without overwriting owner changes. The exact business meaning of the conditional +72h guard remains OQ-014; survey/test and AI behavior remain future work.
- Clarified that retention considers only future `REQUESTED` and `CONFIRMED` bookings within the configured window; onboarding re-engagement consumes the existing internal progress record and does not restore the removed client-facing wizard.

## 2026-08-14 — Owner-confirmed authenticated Client Portal IA

- Superseded the visible four-stage onboarding presentation in REQ-PORTAL-003 with an internal versioned progress record; authenticated clients enter the useful Portal immediately and the legacy onboarding route is not a product destination.
- Confirmed REQ-PORTAL-004 progressive profiling behavior: optional fields do not globally gate Portal access, action-specific data is collected just in time, and attribution/technical fields are derived or hidden.
- Confirmed a CHUKLOV-first authenticated shell, persistent RU/EN client locale selection, desktop navigation, Mini App/mobile bottom navigation, direct Profile access, and authentication UI disappearing after success.
- Confirmed that existing immutable/versioned consent semantics remain unchanged; absent an explicit global legal gate, published consents are presented directly in Profile rather than as a generic onboarding step.

## 2026-08-14 — Owner-confirmed Phase 1 specialist selection

- Confirmed that the current client booking journey has one bookable specialist, Евгений Чуклов, so the Portal preselects that specialist and does not ask the client to make a redundant choice.
- Confirmed that the specialist selector remains available when a service later has multiple eligible specialists; the domain assignment and authorization rules remain unchanged.

## 2026-08-14 — Owner-confirmed calendar booking interaction

- Confirmed that REQ-BOOKING-002 client availability is presented as a focused service/specialist/format/calendar/time/confirmation journey rather than a single form with a multi-day slot dump.
- Confirmed that date range bounds remain an internal availability query detail; ordinary clients see a month calendar, unavailable dates are disabled, and only the selected date's start times are rendered.
- Confirmed that booking creation continues to use the authoritative server slot and existing UTC/IANA, assignment, conflict, idempotency, and organization-scope guarantees.

## 2026-08-14 — Owner-confirmed booking visual remediation

- Confirmed that the client booking surface must materially follow the approved CHUKLOV reference: a calm contained wizard, readable responsive stepper, service rows, format chips, calendar/time card composition, and clean confirmation rather than a backend-shaped form.
- Confirmed that mobile and Telegram Mini App clipping, collapsed weekday/date grids, horizontal overflow, and unreadable time walls are blocking defects; the same selected-day-only availability behavior applies at narrow widths.
- Confirmed that a single bookable Евгений Чуклов is server-selected and omitted from the intermediate choice/context UI, while the selected specialist remains visible as confirmation information and a future multi-specialist service can expose a real choice step.

## 2026-08-14 — Owner-confirmed Telegram web authentication

- Amended REQ-PORTAL-005 and REQ-SEC-004 so ordinary web offers Telegram and passwordless email as independent authentication methods.
- Confirmed a short-lived, single-use, browser-bound Telegram deep-link handshake using authentic bot evidence; verified identities resolve an existing organization-scoped Client or create one without matching by display name or username.
- Retained verified fresh non-replayed initData for Mini App authentication and session regeneration after every successful authentication method.

## 2026-08-14 — Telegram Mini App automatic authentication

- Clarified REQ-PORTAL-002 so a Mini App with valid Telegram initData submits verified authentication once on entry, without a second manual login button.
- Confirmed that failures expose a localized retry action and never trust frontend identity fields or automatically authenticate an ordinary browser.

## 2026-08-13 — M4 final narrow remediation

- Verified the REQ-SCHEDULING-001 schedule-impact acknowledgement boundary in Filament, including staff-visible affected-booking previews, retained digests, changed-set rejection, quick Specialist deactivation, and exception deletion.
- Verified REQ-TIMEZONE-002 display-local date handling across DST midnight transitions and synchronized current client timezone preference on Portal reschedule surfaces without rewriting historical booking metadata.
- Verified REQ-BOOKING-010 retry-safe creation key rotation: missing keys remain rejected, same-intent retries retain their key, and accepted distinct intents receive a fresh key.

## 2026-08-13 — M4 independent-review remediation

- Amended REQ-TIMEZONE-002, REQ-BOOKING-007, REQ-BOOKING-009, REQ-BOOKING-010, and REQ-SCHEDULING-001 with verified stale-command protection, required durable creation idempotency, current-preference display semantics, safe CRM history projections, and bound schedule-impact acknowledgement.
- Verified the amendments with adverse Unit/Feature coverage, PostgreSQL process-level reschedule and same-key creation races, BookingEvent DELETE immutability coverage, full quality/CI gates, and shared Portal desktop/mobile E2E coverage.

## 2026-08-13 — M4C owner decisions and implementation traceability

- Resolved OQ-002 with an organization-configurable cancellation/reschedule cutoff, initial Phase 1 default 1440 minutes, staff reasoned override, pending HOME_VISIT withdrawal, stable booking identity/calendar UID on reschedule, and no M4 payment/refund consequences.
- Resolved OQ-010 with explicit future-booking impact calculation, CRM warning/acknowledgement, durable booking preservation, derived scheduling-attention visibility, and audited acknowledgement.
- Resolved OQ-011 with dedicated typed terminal NO_SHOW status and staff/time authorization; rejected requests remain separate.
- Resolved OQ-012 with one organization-level Phase 1 office context and booking-specific HOME_VISIT destination data; no speculative Location aggregate or multi-site capacity.
- Updated REQ-TIMEZONE-001/002, REQ-BOOKING-001/003/004/005/006/007/011, and REQ-SCHEDULING-001 to record the accepted M4 lifecycle, timezone, payment-handoff, schedule-impact, and location boundaries.

## 2026-08-13 — Owner-review backlog normalization

- Added Phase 1/M4 requirements for idempotent booking creation, schedule-mutation safety, and explicit operational booking outcomes; linked unresolved schedule-conflict, `NO_SHOW`, and location-model decisions to OQ-010 through OQ-012.
- Added future CRM/security/data requirements for controlled client merge, staff MFA/session revocation, historical retention semantics, client data lifecycle, and support impersonation controls.
- Added future communication-preference requirements, clarified the attachment malware-scanning/quarantine production boundary, and extended AI governance requirements for classified-data policy, bounded usage/cost, safe provider failure, and preserved provenance.
- Added OQ-013 for jurisdiction-specific client data lifecycle rules. M4 implementation status and active plans remain unchanged.

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
