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
- MedicalProfiles: encrypted Class C medical data at rest, key resolver seam, authenticated envelopes, application actions, and strict organization authorization boundary.
- Sessions: organization-scoped specialist-confirmed medical Session persistence, create/read/update actions, bounded client history and current/previous dynamics projection, and explicit same-client links to existing medical attachments. Filament remains an orchestration layer; there is no Session delete or AI behavior.
- Attachments: private storage boundary, server-side MIME sniffing, raw DICOM rejection, deterministic scanner/quarantine lifecycle, streaming downloads, and organization ownership enforcement.
- Surveys: organization-scoped versioned declarative definitions, encrypted immutable attempts/results/reports, deterministic scoring and compatible repeat comparison, structured CRM management, shared Portal flow, and typed Scenario events. It contains no AI behavior.
- Knowledge: organization-scoped authored/private-upload sources, immutable revisions, durable idempotent ingestion runs, deterministic chunking, provider-neutral embeddings, PostgreSQL/pgvector retrieval through `KnowledgeRetriever`, structured provenance, and untrusted-content boundaries. It contains no end-user AI answer generation.
- Attribution: bounded allowlisted first-touch and pre-auth provenance, immutable organization/client acceptance, and manual-source fallback. It owns normalized attribution; legacy Client source fields remain compatibility projections only.
- Referrals: stable opaque organization-scoped referral identities, first-valid registration relationships, paid-conversion observation evidence, and Portal/CRM projections. Reward economics and the final bonus ledger remain blocked by OQ-007.
- Feedback: organization-scoped NPS configuration and client submissions, with encrypted internal feedback and bounded CRM visibility. External review links are display-only HTTPS configuration and are never fetched by the server.

Planned bounded contexts are those listed in the master plan. Add a module only with relevant REQ IDs. Cross-module writes go through Application actions; do not reach through another module’s infrastructure.
