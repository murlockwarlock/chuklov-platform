# Modules and Dependencies

Modules follow `app/Modules/<Module>/{Application,Domain,Infrastructure}`. Conventional Laravel entry points adapt requests and delegate.

Current Phase 1 modules:

- Organizations: Organization model, server-derived `OrganizationContext`, memberships, roles, settings, and feature controls.
- Identity: client profiles, organization-scoped channel identities, consent records, and verified channel authentication/linking actions.
- Security: encrypted organization credentials, audit events, and sensitive log redaction.
- Services: organization-scoped catalog records, configuration validation, pricing representation, and create/update/list application actions. Catalog records distinguish services from physical and online products without implementing commerce.
- Specialists: organization-owned practitioner identity and membership-backed optional staff link. Specialist-Service eligibility is an explicit Scheduling-owned assignment relation; no implicit capability is inferred from the Specialist record.
- Scheduling: organization-scoped working hours, exceptions, unavailability, lead time, Specialist-Service assignments, availability calculation, booking lifecycle, immutable booking events, and PostgreSQL conflict protection.
- Content: organization-scoped localized CRM-managed sections consumed by approved portal routes.
- AI: SDK agent fake path only.
- ClientPortal: organization-scoped client session context, onboarding/progressive profiling actions, and portal entry points.
- Conversations: organization/client-scoped normalized conversation and message persistence.
- Channels: capability-aware `MessagingChannel` boundary, Nutgram verification/menu adapter, and no business logic in handlers.
- Scenarios: durable organization-scoped scenario events, typed rules/conditions, versioned notification templates, recipient/channel resolution, scheduled actions, delivery attempts, and CRM configuration/history.
- Integration: minimal module-neutral durable integration-event boundary for typed post-commit evidence with idempotency, retry, and processing fences; it is not a notification transport, event-sourcing store, or message broker.
- MedicalProfiles: encrypted Class C medical data at rest, key resolver seam, authenticated envelopes, application actions, and strict organization authorization boundary.
- Sessions: organization-scoped specialist-confirmed medical Session persistence, create/read/update actions, bounded client history and current/previous dynamics projection, and explicit same-client links to existing medical attachments. Filament remains an orchestration layer; there is no Session delete or AI behavior.
- Attachments: private storage boundary, server-side MIME sniffing, raw DICOM rejection, immediate usability after validation, streaming downloads, and organization ownership enforcement.
- Surveys: organization-scoped versioned declarative definitions, encrypted immutable attempts/results/reports, deterministic scoring and compatible repeat comparison, structured CRM management, shared Portal flow, and typed Scenario events. It contains no AI behavior.
- Knowledge: organization-scoped authored/private-upload sources, immutable revisions, durable idempotent ingestion runs, deterministic chunking, provider-neutral embeddings, PostgreSQL/pgvector retrieval through `KnowledgeRetriever`, structured provenance, and untrusted-content boundaries. It contains no end-user AI answer generation.
- Attribution: bounded allowlisted first-touch and pre-auth provenance, immutable organization/client acceptance, and manual-source fallback. It owns normalized attribution; legacy Client source fields remain compatibility projections only.
- Referrals: stable opaque organization-scoped referral identities, product-neutral first-touch relationships with automatic/manual provenance, neutral commercial evidence consumption, and Portal/CRM projections. Relationship establishment, finance evidence, future conversion qualification, and reward accounting remain separate; reward economics remain blocked by OQ-007.
- If Finance settlement evidence arrives before a referral relationship exists, Referrals retains the neutral evidence with no relationship foreign key. A later manual relationship does not rewrite that historical observation or award anything retroactively; future authorized qualification logic may correlate the retained evidence under an explicit program rule.
- Feedback: organization-scoped NPS configuration and client submissions, with encrypted internal feedback and bounded CRM visibility. External review links are display-only HTTPS configuration and are never fetched by the server.
- Broadcasts: organization-scoped marketing campaigns, typed allowlisted segment queries, immutable draft-revision-bound audience/template snapshots, centralized eligibility, bounded batch/recipient claims, pre-I/O delivery evidence, durable scheduler recovery, and CRM orchestration. Ambiguous external delivery is terminal unknown unless a channel contract proves a safe retry. It depends on Identity/Consent, Scheduling, Surveys, Attribution/Referrals, Scenarios templates, and the Channels outbound boundary; it does not own those source records or call a provider adapter directly. Marketing templates remain Broadcast-only and execution revalidates creator authority.
- B2B: organization-scoped specialist segmentation, durable lead and SalesCall acquisition records, shared Scheduling occupancy, provider-neutral video meeting projection, and bounded CRM handoff operations. It uses existing Identity, Content, Channels, Scenarios, Scheduling, Security, and Organizations application boundaries; it does not create clinical Booking, Finance, or Phase 2 tenant/SaaS state.
- Analytics: read-only organization-scoped aggregate projections over authoritative module records for the CRM dashboard. It owns no lifecycle state, analytics tables, ETL, or cached business truth; it does not mutate source records or expose sensitive payloads.

Planned bounded contexts are those listed in the master plan. Add a module only with relevant REQ IDs. Cross-module writes go through Application actions; do not reach through another module’s infrastructure.
