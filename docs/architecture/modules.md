# Modules and Dependencies

Modules follow `app/Modules/<Module>/{Application,Domain,Infrastructure}`. Conventional Laravel entry points adapt requests and delegate.

Current Phase 1 modules:

- Organizations: Organization model, server-derived `OrganizationContext`, memberships, roles, settings, and feature controls.
- Identity: client profiles, organization-scoped channel identities, consent records, and verified channel authentication/linking actions.
- Security: encrypted organization credentials, audit events, and sensitive log redaction.
- Services: minimal proof model and create/update/list application actions.
- AI: SDK agent fake path only.
- ClientPortal: organization-scoped client session context, onboarding/progressive profiling actions, and portal entry points.
- Conversations: organization/client-scoped normalized conversation and message persistence.
- Channels: capability-aware `MessagingChannel` boundary, Nutgram verification/menu adapter, and no business logic in handlers.

Planned bounded contexts are those listed in the master plan. Add a module only with relevant REQ IDs. Cross-module writes go through Application actions; do not reach through another module’s infrastructure.
