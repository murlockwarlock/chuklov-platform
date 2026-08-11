# Backup and Restore

`make backup` creates a PostgreSQL custom-format dump from the local Compose service in `BACKUP_DIR` (default `./backups`, ignored by Git). Production backup must include PostgreSQL, private files, and required settings.

A backup is not considered valid until a documented isolated restore test succeeds. Production schedule, encryption, retention, offsite copy, and restore RTO/RPO are M16 decisions.
