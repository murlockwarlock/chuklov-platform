# Data Model Foundation

Current foundational tables: `organizations`, `users`, `organization_memberships`, `services`, `clients`, `client_channel_identities`, `client_email_auth_challenges`, `client_channel_link_tokens`, `legal_documents`, `client_consents`, `client_onboardings`, `conversations`, `conversation_messages`, `organization_settings`, `organization_feature_flags`, `organization_credentials`, `audit_events`, Laravel session/cache/job tables, Horizon runtime data in Redis, and Laravel AI conversation tables.

`organization_id` owns organization memberships, services, clients, client identities, consents, settings, feature controls, credentials, and audit events. Membership roles are durable and explicit; legacy user ownership fields are backfilled during M1 and retained until a later safe contraction release. Composite organization/client foreign keys prevent cross-organization identity and consent links. Compound uniqueness/indexes include organization where identity is organization-relative.

Settings use a typed value column selected by a constrained key/type registry. Credentials use encrypted JSON casts and retain only masked output paths. Audit metadata is action-allowlisted before persistence and is not a copy of sensitive business content.

M2 onboarding records are unique per organization/client/flow version and use a composite organization/client foreign key. Conversations and messages use the same composite ownership boundary; channel/external conversation keys and provider message identifiers have organization-scoped uniqueness for deterministic idempotency.

Email authentication challenges are organization/email unique and store only a code hash, attempt count, expiry, and consumption instant. Telegram connection tokens store only a hash and bind one flow to one organization/client. Legal documents are organization-scoped, and consent evidence has a composite organization/document foreign key so a Client cannot reference another organization’s document.

Real M2 instants use PostgreSQL timezone-aware timestamp columns. Date-only future values use `DATE`; display formats never enter the model. Organization default timezone context is validated through the IANA identifier boundary described in `date-time.md`.

PostgreSQL is authoritative. Migrations are forward-only by default and use FKs/indexes. See ADR-002, ADR-006, and the database-migrations skill.
