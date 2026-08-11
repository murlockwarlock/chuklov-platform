#!/usr/bin/env bash
set -euo pipefail

backup_dir="${BACKUP_DIR:-./backups}"
mkdir -p "$backup_dir"
docker-compose exec -T postgres pg_dump -U chuklov -d chuklov -Fc > "$backup_dir/chuklov-$(date -u +%Y%m%dT%H%M%SZ).dump"
