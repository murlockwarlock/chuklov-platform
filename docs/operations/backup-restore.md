# Backup and Restore

`make backup` creates a PostgreSQL custom-format dump from the local Compose service in `BACKUP_DIR` (default `./backups`, ignored by Git). Production backup must include PostgreSQL, private files, and required settings.

A backup is not considered valid until a documented isolated restore test succeeds. Production schedule, encryption, retention, offsite copy, and restore RTO/RPO are M16 decisions.

## Isolated PostgreSQL restore

Run this only against the local production-like Compose PostgreSQL service. Never use a production database as `RESTORE_DB`.

```bash
make infra-wait
make backup

BACKUP_PATH="$(ls -t backups/chuklov-*.dump | head -n 1)"
RESTORE_DB=chuklov_restore_YYYYMMDD

docker-compose exec -T postgres psql -U chuklov -d postgres -v ON_ERROR_STOP=1 \
  -c "CREATE DATABASE ${RESTORE_DB} OWNER chuklov;"

docker-compose exec -T postgres pg_restore -U chuklov -d "${RESTORE_DB}" \
  --exit-on-error --no-owner --no-privileges < "${BACKUP_PATH}"

DB_URL= DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE="${RESTORE_DB}" DB_USERNAME=chuklov DB_PASSWORD=chuklov_local \
php artisan migrate --force --no-ansi

DB_URL= DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE="${RESTORE_DB}" DB_USERNAME=chuklov DB_PASSWORD=chuklov_local \
php artisan migrate:status --no-ansi

docker-compose exec -T postgres psql -U chuklov -d "${RESTORE_DB}" -Atc \
  "SELECT 'organizations=' || count(*) FROM organizations
   UNION ALL SELECT 'users=' || count(*) FROM users
   UNION ALL SELECT 'bookings=' || count(*) FROM bookings
   UNION ALL SELECT 'financial_obligations=' || count(*) FROM financial_obligations
   UNION ALL SELECT 'tracker_plans=' || count(*) FROM tracker_plans
   UNION ALL SELECT 'audit_events=' || count(*) FROM audit_events;"
```

The 2026-09-09 M15 drill restored `backups/chuklov-20260909T090312Z.dump` into `chuklov_m15_restore_20260909`. The source counts of 1 organization, 264 users, 551 bookings, and 1,347 audit events were preserved. The candidate migration set completed at 122 migrations, and the application booted against the restored database with the `health` route available.
