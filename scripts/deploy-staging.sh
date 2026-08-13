#!/usr/bin/env bash
set -Eeuo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
deploy_env="${STAGING_DEPLOY_ENV:-$repository_root/.env.staging-deploy}"

if [[ ! -f "$deploy_env" ]]; then
    echo "Missing deployment environment: $deploy_env" >&2
    exit 1
fi

set -a
source "$deploy_env"
set +a

: "${STAGING_HOST:?Missing STAGING_HOST}"
: "${STAGING_USER:?Missing STAGING_USER}"
: "${STAGING_SSH_KEY:?Missing STAGING_SSH_KEY}"
: "${STAGING_ROOT:?Missing STAGING_ROOT}"
: "${STAGING_PROJECT:?Missing STAGING_PROJECT}"
: "${STAGING_HEALTH_URL:?Missing STAGING_HEALTH_URL}"
: "${STAGING_EXPECTED_HOST_PORT:?Missing STAGING_EXPECTED_HOST_PORT}"

revision="${1:-}"
expected_revision="${2:-}"

if [[ ! "$revision" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Usage: scripts/deploy-staging.sh <40-character-revision> [expected-current-revision]" >&2
    exit 1
fi

if [[ -n "$expected_revision" && ! "$expected_revision" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Expected current revision must be a 40-character SHA." >&2
    exit 1
fi

cd "$repository_root"
resolved_revision="$(git rev-parse "$revision^{commit}")"

if [[ "$resolved_revision" != "$revision" ]]; then
    echo "Revision must be the exact full commit SHA." >&2
    exit 1
fi

if ! git merge-base --is-ancestor "$revision" origin/main; then
    echo "Revision is not reachable from origin/main." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Working tree must be clean before deployment." >&2
    exit 1
fi

temporary_directory="$(mktemp -d)"
archive="$temporary_directory/chuklov-$revision.tar"
trap 'rm -rf "$temporary_directory"' EXIT
git archive --format=tar --output="$archive" "$revision"

ssh_options=(
    -o BatchMode=yes
    -o ConnectTimeout=15
    -o StrictHostKeyChecking=accept-new
    -i "$STAGING_SSH_KEY"
)
remote="${STAGING_USER}@${STAGING_HOST}"
remote_archive="/tmp/chuklov-$revision.tar"

scp "${ssh_options[@]}" "$archive" "$remote:$remote_archive"

ssh "${ssh_options[@]}" "$remote" bash -s -- \
    "$revision" \
    "$expected_revision" \
    "$STAGING_ROOT" \
    "$STAGING_PROJECT" \
    "$STAGING_HEALTH_URL" \
    "$STAGING_EXPECTED_HOST_PORT" \
    "$remote_archive" <<'REMOTE'
set -Eeuo pipefail

report_preflight_failure() {
    local status="$?"
    echo "Staging deployment failed during preflight at remote script line $1 (exit $status)." >&2
    exit "$status"
}

trap 'report_preflight_failure "$LINENO"' ERR

revision="$1"
expected_revision="$2"
root="$3"
project="$4"
health_url="$5"
expected_host_port="$6"
archive="$7"
release="$root/releases/$revision"
compose="$root/compose.yml"
environment="$root/shared/.env"
backups="$root/shared/backups"
current_revision="$(cat "$root/REVISION")"
previous_target="$(readlink -f "$root/current")"
snapshot="$backups/predeploy-$revision"
database_backup="$backups/postgresql-before-$revision.dump"
compose_backup="$backups/compose-before-$revision.yml"

if [[ -n "$expected_revision" && "$current_revision" != "$expected_revision" ]]; then
    echo "Remote revision mismatch: expected $expected_revision, found $current_revision" >&2
    exit 1
fi

if [[ "$current_revision" == "$revision" ]]; then
    echo "Revision $revision is already deployed."
    exit 0
fi

if [[ -e "$release" ]]; then
    echo "Release already exists: $release" >&2
    exit 1
fi

install -d -m 0700 "$snapshot"
install -d -m 0700 "$backups"
date --iso-8601=seconds > "$snapshot/captured-at.txt"
ss -H -lntup | sort > "$snapshot/listening-ports-full.txt"
ss -H -lntup | sed -E 's/pid=[0-9]+/pid=PID/g; s/fd=[0-9]+/fd=FD/g' | sort > "$snapshot/listening-ports.txt"
systemctl list-units --type=service --state=running --no-pager > "$snapshot/running-services.txt"
pm2 jlist > "$snapshot/pm2.json"
pm2 jlist | jq -r '.[] | [.name, .pm2_env.status] | @tsv' | sort > "$snapshot/pm2-status.tsv"
nginx -T > "$snapshot/nginx-effective.txt" 2>&1
nft list ruleset > "$snapshot/nftables.txt"
docker ps --format '{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}' | sort > "$snapshot/docker.txt"
sudo -u postgres psql -Atqc 'select datname from pg_database order by datname' > "$snapshot/host-databases.txt"
chmod 0600 "$snapshot"/*
echo "Pre-deploy host baseline captured."

cd "$root"
docker compose --project-name "$project" --env-file "$environment" -f "$compose" config --quiet

actual_binding="$(docker compose --project-name "$project" --env-file "$environment" -f "$compose" port app 8000)"
if [[ "$actual_binding" != "$expected_host_port" ]]; then
    echo "Unexpected app binding: $actual_binding" >&2
    exit 1
fi

for service in postgres redis app horizon scheduler telegram; do
    docker compose --project-name "$project" --env-file "$environment" -f "$compose" config --services | grep -Fxq "$service"
done
echo "Isolated Compose project and app binding verified."

docker compose --project-name "$project" --env-file "$environment" -f "$compose" exec -T postgres \
    sh -lc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$database_backup"
docker compose --project-name "$project" --env-file "$environment" -f "$compose" exec -T postgres \
    pg_restore -l < "$database_backup" > /dev/null
chmod 0600 "$database_backup"
echo "Staging PostgreSQL backup created and validated."
cp -a "$compose" "$compose_backup"
chmod 0600 "$compose_backup"

install -d -m 0750 "$release"
tar -xf "$archive" -C "$release"
printf '%s\n' "$revision" > "$release/REVISION"
ln -s /app/storage/app/public "$release/public/storage"

docker build -t "chuklov-staging-app:$revision" -f "$release/docker/php/Dockerfile" "$release"
docker run --rm --env-file "$environment" \
    -v "$release:/app" \
    -v "$root/shared/storage:/app/storage" \
    -v "$root/shared/bootstrap-cache:/app/bootstrap/cache" \
    -w /app "chuklov-staging-app:$revision" \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
docker run --rm -v "$release:/app" -w /app node:24.6.0-alpine npm ci --ignore-scripts
docker run --rm -v "$release:/app" -w /app node:24.6.0-alpine npm run build

sed -E "s#chuklov-staging-app:[0-9a-f]{40}#chuklov-staging-app:$revision#g" "$compose_backup" > "$compose.next"
if [[ "$(grep -c "chuklov-staging-app:$revision" "$compose.next")" -lt 4 ]]; then
    echo "Compose image replacement did not cover all runtime services." >&2
    exit 1
fi
docker compose --project-name "$project" --env-file "$environment" -f "$compose.next" config --quiet
chmod 0640 "$compose.next"

rollback() {
    echo "Deployment failed; restoring application release $current_revision." >&2
    ln -s "$previous_target" "$root/current.rollback"
    mv -Tf "$root/current.rollback" "$root/current"
    cp "$compose_backup" "$compose"
    cd "$root"
    docker compose --project-name "$project" --env-file "$environment" -f "$compose" up -d --no-deps --force-recreate app horizon scheduler telegram || true
}
trap rollback ERR

mv "$compose.next" "$compose"
ln -s "$release" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"

docker compose --project-name "$project" --env-file "$environment" -f "$compose" run --rm --no-deps app php artisan migrate --force < /dev/null
docker compose --project-name "$project" --env-file "$environment" -f "$compose" run --rm --no-deps app php artisan optimize < /dev/null
docker compose --project-name "$project" --env-file "$environment" -f "$compose" run --rm --no-deps app php artisan filament:optimize < /dev/null
docker compose --project-name "$project" --env-file "$environment" -f "$compose" up -d postgres redis
docker compose --project-name "$project" --env-file "$environment" -f "$compose" up -d --no-deps --force-recreate app horizon scheduler telegram
docker compose --project-name "$project" --env-file "$environment" -f "$compose" up -d --wait

curl --noproxy '*' --fail --silent --show-error --retry 10 --retry-delay 2 "$health_url"
docker compose --project-name "$project" --env-file "$environment" -f "$compose" exec -T app php artisan horizon:status < /dev/null
docker compose --project-name "$project" --env-file "$environment" -f "$compose" ps --status running --services | grep -Fxq app
docker compose --project-name "$project" --env-file "$environment" -f "$compose" ps --status running --services | grep -Fxq horizon
docker compose --project-name "$project" --env-file "$environment" -f "$compose" ps --status running --services | grep -Fxq scheduler
docker compose --project-name "$project" --env-file "$environment" -f "$compose" ps --status running --services | grep -Fxq telegram

nginx -t
for service in nginx pm2-root postgresql@16-main webstore-darimiru docker; do
    systemctl is-active --quiet "$service"
done
cmp -s "$snapshot/nginx-effective.txt" <(nginx -T 2>&1)
cmp -s "$snapshot/nftables.txt" <(nft list ruleset 2>/dev/null)
cmp -s "$snapshot/host-databases.txt" <(sudo -u postgres psql -Atqc 'select datname from pg_database order by datname')
cmp -s "$snapshot/listening-ports.txt" <(ss -H -lntup | sed -E 's/pid=[0-9]+/pid=PID/g; s/fd=[0-9]+/fd=FD/g' | sort)
cmp -s "$snapshot/pm2-status.tsv" <(pm2 jlist | jq -r '.[] | [.name, .pm2_env.status] | @tsv' | sort)

printf '%s\n' "$revision" > "$root/REVISION"
chmod -R a-w "$release"
rm -f "$archive"
trap - ERR

echo
echo "Successfully deployed $revision"
docker compose --project-name "$project" --env-file "$environment" -f "$compose" ps
REMOTE
