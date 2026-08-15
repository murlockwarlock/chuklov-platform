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

`make setup` is safely repeatable and preserves an existing `APP_KEY`.

Portal: `http://localhost:8000`. CRM: `http://localhost:8000/admin`. Health: `http://localhost:8000/health`.

Compose initializes `chuklov` for development and an isolated `chuklov_test` database for integration tests.

Create an admin deliberately with `php artisan make:filament-user`, then associate it with an organization and set `is_admin=true`. No default credentials are seeded.

## Quality

Local development feedback (no Docker/Playwright/containers):

```bash
make check-fast          # unit + feature tests, lint, static analysis
php artisan test --filter=TestName  # targeted single test
vendor/bin/pint --dirty  # format changed files
```

Full verification runs on GitHub Actions hosted CI. Push a candidate SHA and let the workflow run. All existing commands remain available for explicit local use:

```bash
make quality             # unit + feature + lint + static + build + audit
make ci                  # quality + PostgreSQL/Redis integration tests
make test-e2e            # Playwright desktop/mobile
make privacy             # Docker context + secret scan
```

See `AGENTS.md` for contribution rules and `docs/index.md` for context routing.
