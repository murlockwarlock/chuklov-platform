# Scheduling

Scheduling stores UTC instants and IANA zones, recalculates availability server-side, and protects conflicts transactionally. Office, home, and online formats have distinct typed representations; cancellation consequences await OQ-002.

## M4A decisions

- CalculateAvailability is the single Application entry point for staff, portal, and booking-time checks. It returns an explicit projection rather than Eloquent models.
- Recurring working hours and date exceptions are local wall-clock values. Unavailable periods and booking intervals are real TIMESTAMPTZ instants. Specialist timezone overrides the organization default for schedule interpretation; client timezone is a separate display context.
- Custom date windows replace recurring windows for that date, and a day-off exception suppresses the date. Active unavailable periods and blocking booking states are subtracted from the resulting schedule.
- A slot consumes service duration_minutes plus buffer_minutes; the initial lead-time precedence is organization-only through booking_lead_time_minutes. A future service/specialist/location precedence must be added as an explicit policy, not inferred.
- PostgreSQL composite ownership foreign keys and GiST exclusion constraints enforce tenant-safe interval invariants. Booking creation also locks the organization-owned specialist row inside its transaction so concurrent application paths serialize without a global organization lock.
- The PostgreSQL integration suite verifies the exclusion invariant through rolled-back transaction attempts and a process-level parallel transaction harness. The database exclusion constraint and specialist-row lock are the production safety mechanisms.
- Booking and payment states are separate. M4A creates only the booking foundation and its immutable created event; it does not implement payment effects, cancellation/reschedule rules, external calendars, or provider links.
- M4A initially accepted an explicitly supplied pair while OQ-009 was open. M4B replaces that boundary with an explicit organization-scoped `specialist_service_assignments` many-to-many relation. Availability and booking creation require the assignment; it does not encode qualifications, pricing, duration overrides, or other professional metadata.

## M4B decisions

- Assignment rows are tenant-owned, unique per organization/specialist/service, and protected by composite ownership foreign keys. Authorized CRM staff create and remove them through Application actions; removing an assignment affects only future creation and does not alter historical bookings.
- `PENDING_REVIEW` is non-blocking for `HOME_VISIT`. `REQUESTED` and `CONFIRMED` are the blocking booking states. Home-visit approval locks the booking and relevant Specialist/Service rows, recalculates the exact requested interval through CalculateAvailability, and only then transitions to `CONFIRMED`.
- A home-visit approval fails with a validation error when its preferred time is no longer available. Rejection is a typed non-blocking terminal state and requires an explicit reason. Neither transition implements payment, deposit, refund, cancellation, or reschedule behavior.
- The shared Portal booking journey uses explicit service, Specialist, date/slot, format, and confirmation projections for browser, mobile browser, and Telegram Mini App runtime. CRM list/detail/actions remain organization scoped and call the same Application lifecycle actions.

See REQ-BOOKING-* and REQ-TIMEZONE-*.
