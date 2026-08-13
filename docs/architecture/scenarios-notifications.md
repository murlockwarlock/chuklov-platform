# Scenarios and Notifications

M5A implements the first organization-scoped scenario pipeline:

`ScenarioEvent → ScenarioRule → typed conditions → recipient → channel candidates → pinned template version → ScenarioAction → ScenarioDelivery → attempt history`.

`scenario_events` is the PostgreSQL-backed durable input boundary for scenario-relevant facts. Booking completion writes the ScenarioEvent in the same transaction as the accepted Booking mutation. `BookingEvent` remains immutable Booking business history and `AuditEvent` remains audit/security history; neither is reused as the delivery queue. Queue jobs carry only durable identifiers, while PostgreSQL row locking and state transitions protect materialization and delivery. Redis/Horizon transports and observes work but is not the correctness source.

Rules are organization-scoped, versioned on CRM mutation, and hold typed trigger, editable delay/unit, allowlisted structured conditions, recipient strategy, ordered channel priority, purpose, and a pinned published template version. Unknown condition types fail closed; arbitrary PHP, SQL, Blade execution, `eval`, and unrestricted JSON logic are not supported. Seed timing is data: M5A seeds one editable post-session +24h Booking `COMPLETED` follow-up per supported locale and does not guess the conditional +72h meaning.

Templates have an organization/locale parent and immutable published versions. Rendering exposes only the allowlisted context variables declared by the selected version. Scheduled actions snapshot their source event, rule version and typed conditions, recipient, channel order, render context, schedule instant, and exact template version. Rule disablement remains an intentional live stop control, while later condition edits do not rewrite materialized action semantics. Deliveries have durable per-action/channel idempotency keys, attempt rows, typed delivered/retryable/permanent/unavailable/suppressed outcomes, safe fallback after retry exhaustion, and pre-delivery current-state suppression without deleting history. Stale action claims without an in-flight delivery are released for PostgreSQL rediscovery; an in-flight attempt with no persisted provider outcome remains terminally uncertain to avoid an unsafe duplicate send.

Client recipients resolve through the current organization and verified client channel identity. Internal recipients resolve only active same-organization memberships selected by explicit member IDs or roles and verified organization-member channel identities. A verified identity is not marketing consent; service/transactional purpose remains distinct from future marketing preferences. Telegram is the only established production outbound adapter in M5A; automated tests use deterministic fakes and no network token.

CRM configuration and history are organization-scoped Filament adapters. Mutations delegate to Application actions and write allowlisted audit metadata; history projections omit raw event payloads, provider payloads, secrets, and unrestricted render context.

M5B still owns the remaining post-session family, abandoned onboarding/re-engagement, retention/no-next-booking, broader internal-recipient scenarios, and the exact +72h condition owner decision. See REQ-NOTIFY-*, REQ-CHANNEL-*, REQ-CONVERSATION-001, ADR-009, ADR-012, and ADR-004.
