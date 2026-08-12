# Scheduling

Scheduling stores UTC instants and IANA zones, recalculates availability server-side, and protects conflicts transactionally. Office, home, and online formats have distinct typed representations; cancellation consequences await OQ-002.

## M4A decisions

- CalculateAvailability is the single Application entry point for staff, portal, and booking-time checks. It returns an explicit projection rather than Eloquent models.
- Recurring working hours and date exceptions are local wall-clock values. Unavailable periods and booking intervals are real TIMESTAMPTZ instants. Specialist timezone overrides the organization default for schedule interpretation; client timezone is a separate display context.
- Custom date windows replace recurring windows for that date, and a day-off exception suppresses the date. Active unavailable periods and blocking booking states are subtracted from the resulting schedule.
- A slot consumes service duration_minutes plus buffer_minutes; the initial lead-time precedence is organization-only through booking_lead_time_minutes. A future service/specialist/location precedence must be added as an explicit policy, not inferred.
- PostgreSQL composite ownership foreign keys and GiST exclusion constraints enforce tenant-safe interval invariants. Booking creation also locks the organization-owned specialist row inside its transaction so concurrent application paths serialize without a global organization lock.
- The PostgreSQL integration suite verifies the exclusion invariant through rolled-back transaction attempts. A process-level parallel transaction harness is not included in M4A; the database exclusion constraint and specialist-row lock are the production safety mechanisms, while a true parallel-driver stress test remains pre-production hardening.
- Booking and payment states are separate. M4A creates only the booking foundation and its immutable created event; it does not implement payment effects, cancellation/reschedule rules, external calendars, or provider links.
- M4A accepts an explicitly supplied valid organization + specialist + service pair for availability computation. It creates no specialist-service mapping and makes no professional eligibility claim while OQ-009 is open.

See REQ-BOOKING-* and REQ-TIMEZONE-*.
