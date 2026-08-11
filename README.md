# Chuklov Platform

Modular Laravel application powering the Chuklov CRM, responsive Client Portal, and channel adapters. Milestone status lives in `PROJECT_STATUS.md`; product behavior is normalized in `docs/product/requirements.md`.

## Requirements

- PHP 8.5 and Composer 2.10, or Docker/Compose
- Node 24+ and npm 11+
- Docker engine for PostgreSQL 18 + pgvector 0.8.2 and Redis 8.2

## Setup

```bash
make setup
make up
```

Portal: `http://localhost:8000`. CRM: `http://localhost:8000/admin`. Health: `http://localhost:8000/health`.

Compose initializes `chuklov` for development and an isolated `chuklov_test` database for integration tests.

Create an admin deliberately with `php artisan make:filament-user`, then associate it with an organization and set `is_admin=true`. No default credentials are seeded.

## Quality

```bash
make test
make lint
make static
make quality
make ci
make test-e2e
```

See `AGENTS.md` for contribution rules and `docs/index.md` for context routing.
