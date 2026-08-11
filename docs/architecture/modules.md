# Modules and Dependencies

Modules follow `app/Modules/<Module>/{Application,Domain,Infrastructure}`. Conventional Laravel entry points adapt requests and delegate.

Current M0 modules:

- Organizations: Organization model and server-derived `OrganizationContext`.
- Services: minimal proof model and create/update/list application actions.
- AI: SDK agent fake path only.
- Channels: Nutgram configuration/handler boundary only.

Planned bounded contexts are those listed in the master plan. Add a module only with relevant REQ IDs. Cross-module writes go through Application actions; do not reach through another module’s infrastructure.
