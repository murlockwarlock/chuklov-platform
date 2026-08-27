# ADR-020: B2B Sales-Call Video Projection and Shared Occupancy

- Status: Proposed for M11D review
- Date: 2026-08-27

## Context

Phase 1 B2B sales consultations need a durable local schedule and optional Zoom meeting without becoming clinical Bookings or creating a second specialist calendar. Zoom operations are external, retryable, and independently failure-prone. Booking creation already protects Specialist availability with a row lock and PostgreSQL interval exclusion authority.

## Decision

1. `B2bSalesCall` is the business source of truth for the operational sales-call lifecycle and scheduled interval.
2. A typed linked `UnavailablePeriod` row is the derived occupancy projection for a scheduled B2B call. Only SalesCall Application actions create, move, or remove that projection; operators do not edit it as an independent unavailable period.
3. SalesCall creation and rescheduling acquire the existing Specialist row lock and validate Booking, UnavailablePeriod, and B2B occupancy together before committing the local schedule. Booking and SalesCall transactions therefore share one conflict authority.
4. Video provisioning uses the provider-neutral `VideoMeetingProvider` boundary. Zoom is an adapter using Server-to-Server OAuth. Provider create, update, cancel, reconciliation, and host-launch operations occur outside local scheduling transactions through durable post-commit integration state.
5. Provider synchronization state is separate from SalesCall operational state. A scheduled call remains reserved when Zoom is pending or failed; a manual HTTPS link is an explicit fallback mode, not a fabricated provider meeting.

## Consequences

- B2B calls block ordinary bookings and other B2B calls for the same Specialist, while cancellation releases the same interval and rescheduling moves it atomically.
- B2B calls do not create Booking, visit, medical, payment, revenue, or debt records, so existing Scheduling analytics and Finance ledgers remain uncontaminated.
- PostgreSQL composite ownership, typed linkage, and exclusion constraints enforce organization-safe projection integrity. Provider response loss is handled by durable operation identity and deterministic reconciliation rather than blind meeting creation.
- Zoom credentials remain organization-scoped encrypted configuration. Client-facing data stores only the join URL; host launch URLs are obtained server-side for authorized CRM actions and are not durable business truth.
- This decision does not introduce external calendar synchronization, Phase 2 tenant provisioning, white-label SaaS, subscription billing, or a parallel calendar authority.
