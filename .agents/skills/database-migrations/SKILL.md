---
name: database-migrations
description: Design and verify Chuklov database migrations, PostgreSQL constraints, organization scoping, pgvector usage, and rollback safety. Use whenever schema or persistence changes.
---

# Database Migrations

1. Read `AGENTS.md`, `docs/architecture/data-model.md`, and the affected requirements.
2. Use PostgreSQL semantics in production; do not design only for SQLite test convenience.
3. Include `organization_id` and suitable compound indexes on tenant-owned data.
4. Prefer database constraints for invariants the database can enforce.
5. Make `up()` and `down()` deliberate, and assess existing-data behavior before destructive changes.
6. Keep pgvector changes compatible with the pinned version in `docs/architecture/dependency-matrix.md`.
7. Add migration or integration coverage and run `make test-integration` for PostgreSQL-specific behavior.
8. Document an irreversible or operationally sensitive migration in `docs/operations/` before release.
