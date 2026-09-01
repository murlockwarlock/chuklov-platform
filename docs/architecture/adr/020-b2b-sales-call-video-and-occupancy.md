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
6. Provider correlation uses a short opaque per-generation marker at the beginning of Zoom's documented `agenda` field. List reconciliation uses only documented ordinary meeting fields, bounded `next_page_token` pagination, and exact marker matching. An unknown create outcome, ambiguous match, incomplete pagination, or zero match remains reconciliation-required and cannot trigger a blind automatic create.
7. Provider work claims a durable database lease and fence tied to the current IntegrationEvent processing token and SalesCall provider-sync generation. One absolute operation deadline covers OAuth, reconciliation, and mutation calls; the cache is not the correctness authority, and stale workers cannot finalize newer state.
8. B2B sales-call duration is an organization-scoped, authorized CRM setting. It is a positive bounded whole number with no implicit business default; local SalesCall intervals retain the duration captured at creation, while future availability and provider projections use the configured duration.
9. The `b2b.sales_call.ready` event carries the exact organization, SalesCall version, provider-sync version/generation, correlation key, and meeting mode. Materialization and delivery both revalidate the current scheduled call and current HTTPS client URL before any channel send.
10. The typed `b2b_specialist_answer` Yes/No value is the sole authority for self-declared massage/bodywork-specialist classification. The legacy `b2b_role` field remains a separate historical/presentation attribute and is not used to infer that answer.
11. Zoom known-meeting identities carry the non-secret provider principal `account_id + host_user_id`. The active credential must match that principal before known-meeting provider I/O; same-principal client or secret rotation is allowed, while a principal change or missing affinity fails closed into reconciliation. Every new automatic generation captures its principal before its durable provider event and provider I/O.

## Consequences

- B2B calls block ordinary bookings and other B2B calls for the same Specialist, while cancellation releases the same interval and rescheduling moves it atomically.
- B2B calls do not create Booking, visit, medical, payment, revenue, or debt records, so existing Scheduling analytics and Finance ledgers remain uncontaminated.
- PostgreSQL composite ownership, typed linkage, and exclusion constraints enforce organization-safe projection integrity. Provider response loss is handled by durable operation identity and deterministic reconciliation rather than blind meeting creation.
- Zoom credentials remain organization-scoped encrypted configuration. Client-facing data stores only the join URL; host launch URLs are obtained server-side for authorized CRM actions and are not durable business truth.
- Known Zoom meeting operations are fenced to the persisted account/host principal, so replacing an account or host cannot make an old meeting appear absent under the new credential. Only non-secret principal identifiers cross the durable B2B sync boundary; credential material remains encrypted configuration.
- Provider host launch responses are parsed structurally and accepted only for the explicit Zoom host allowlist needed by the provider contract. A stale known provider identity is never converted into an automatic replacement create; cancellation may reconcile exact remote absence, while update, launch, and reconciliation surface safe recovery state.
- This decision does not introduce external calendar synchronization, Phase 2 tenant provisioning, white-label SaaS, subscription billing, or a parallel calendar authority.
