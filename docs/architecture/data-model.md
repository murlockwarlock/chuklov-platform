# Data Model Foundation

Current foundational tables: `organizations`, `users`, `services`, Laravel session/cache/job tables, Horizon runtime data in Redis, and Laravel AI conversation tables.

`organization_id` owns users and services. Compound uniqueness/indexes include organization where identity is organization-relative. Future entities follow `REQ-ORG-*`; schema examples in client documents are not production DDL.

PostgreSQL is authoritative. Migrations are forward-only by default and use FKs/indexes. See ADR-002, ADR-006, and the database-migrations skill.
