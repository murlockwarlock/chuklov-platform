# Scheduling

Scheduling stores UTC instants and IANA zones, recalculates availability server-side, and protects conflicts transactionally. Office, home, and online formats have distinct typed representations. Phase 1 uses one organization-level office context; HOME_VISIT destinations remain booking-specific.

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

## M11D B2B sales-call occupancy

- B2B SalesCalls remain separate from Booking and Finance. A scheduled call owns its local interval and materializes one typed linked `UnavailablePeriod` projection; SalesCall Application actions are the only writers of that projection.
- SalesCall creation and rescheduling use the same Specialist row lock and PostgreSQL exclusion authority as Booking creation. Booking, B2B calls, and ordinary unavailable periods therefore share one conflict boundary without a second calendar algorithm.
- Cancellation removes the linked projection and rescheduling replaces it in the same transaction. Provider synchronization is post-commit and cannot release or move the local reserved interval when Zoom fails.

See ADR-020 and REQ-B2B-001.

## M4C decisions

- Booking creation has a PostgreSQL-backed organization/actor-scoped idempotency record with request-hash comparison, transactional booking linkage, bounded retention, and scheduled pruning. It prevents duplicate logical bookings without replacing the independent PostgreSQL interval exclusion invariant.
- REQUESTED and CONFIRMED block intervals; PENDING_REVIEW HOME_VISIT requests remain non-blocking. Approval rechecks the exact preferred slot inside a transaction before entering CONFIRMED.
- Cancellation and rescheduling use the organization setting booking_cancellation_cutoff_minutes, defaulting to 1440 minutes. Client self-service is cutoff-aware, staff inside-cutoff overrides require a reason, and payment state is never changed as a side effect. Rescheduling preserves Booking identity and calendar_uid, increments event_version, appends immutable history, and keeps the old interval when the target conflicts.
- NO_SHOW is a typed terminal status available to authorized staff after scheduled start. Completion, cancellation, rejection, no-show, rescheduling, and online manual-link changes are explicit Application transitions with immutable BookingEvents and allowlisted audit metadata.
- Schedule mutation actions use one impact calculator for future non-terminal bookings. CRM must acknowledge an impact before saving the mutation; the booking is not rewritten or auto-cancelled and is exposed as a derived scheduling-attention state for later explicit resolution.
- HOME_VISIT approval can record a typed FULL_PAYMENT or configured TRANSPORT_DEPOSIT handoff using integer minor units and currency only. M4 does not claim payment or implement finance. ONLINE manual URLs are Application-authorized; AUTO has no real provider integration or fabricated production link.

See REQ-BOOKING-* and REQ-TIMEZONE-*.
