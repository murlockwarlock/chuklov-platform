---
name: database-migrations
description: Design and verify Chuklov database migrations, PostgreSQL constraints, organization scoping, pgvector usage, and rollback safety. Use whenever schema or persistence changes.
---

# Database Migrations

1. Read `AGENTS.md`, `docs/architecture/data-model.md`, and the affected
   requirements.
2. Design against PostgreSQL semantics; do not choose behavior only because
   SQLite makes a local test convenient.
3. Include `organization_id` and suitable compound indexes on tenant-owned
   data. Prefer database constraints for invariants the database can enforce.
4. Make `up()` and `down()` deliberate, assess existing-data behavior, and
   keep pgvector changes compatible with the pinned version.
5. Add targeted migration or integration coverage. PostgreSQL proof is required
   for DB-sensitive behavior, but the default execution location is hosted CI,
   a remote runner, or authorized staging.
6. Do not make local `make test-integration`, Docker Compose, or a local
   PostgreSQL stack a default step. Use a local heavy stack only when remote
   evidence is unavailable, reproduction requires it, or the owner explicitly
   requests it.
7. Document an irreversible or operationally sensitive migration in
   `docs/operations/` before release.
