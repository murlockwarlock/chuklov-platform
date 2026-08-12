# Data Model Foundation

Current foundational tables: `organizations`, `users`, `organization_memberships`, `services`, `clients`, `client_channel_identities`, `client_consents`, `organization_settings`, `organization_feature_flags`, `organization_credentials`, `audit_events`, Laravel session/cache/job tables, Horizon runtime data in Redis, and Laravel AI conversation tables.

`organization_id` owns organization memberships, services, clients, client identities, consents, settings, feature controls, credentials, and audit events. Membership roles are durable and explicit; legacy user ownership fields are backfilled then removed. Composite organization/client foreign keys prevent cross-organization identity and consent links. Compound uniqueness/indexes include organization where identity is organization-relative.

Settings use a typed value column selected by a constrained key/type registry. Credentials use encrypted JSON casts and retain only masked output paths. Audit metadata is sanitized before persistence and is not a copy of sensitive business content.

PostgreSQL is authoritative. Migrations are forward-only by default and use FKs/indexes. See ADR-002, ADR-006, and the database-migrations skill.
