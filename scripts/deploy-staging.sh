#!/usr/bin/env bash
set -Eeuo pipefail

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "$script_directory/.." && pwd)"
trusted_normalizer="$script_directory/normalize-fail2ban-elements.awk"

if [[ ! -f "$trusted_normalizer" || ! -r "$trusted_normalizer" ]]; then
    echo "Missing trusted fail2ban normalizer: $trusted_normalizer" >&2
    exit 1
fi

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
deployment_ref="${STAGING_DEPLOY_REF:-origin/main}"

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

if [[ ! "$deployment_ref" =~ ^origin/[A-Za-z0-9._/-]+$ ]] || ! git rev-parse --verify "$deployment_ref^{commit}" > /dev/null 2>&1; then
    echo "STAGING_DEPLOY_REF must identify an existing origin remote branch." >&2
    exit 1
fi

if ! git merge-base --is-ancestor "$revision" "$deployment_ref"; then
    echo "Revision is not reachable from $deployment_ref." >&2
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
normalizer="$temporary_directory/normalize-fail2ban-elements.awk"
cp -- "$trusted_normalizer" "$normalizer"

ssh_options=(
    -o BatchMode=yes
    -o ConnectTimeout=15
    -o StrictHostKeyChecking=accept-new
    -i "$STAGING_SSH_KEY"
)
remote="${STAGING_USER}@${STAGING_HOST}"
remote_archive="/tmp/chuklov-$revision.tar"
remote_normalizer="/tmp/chuklov-$revision-normalize-fail2ban-elements.awk"

scp "${ssh_options[@]}" "$archive" "$remote:$remote_archive"
scp "${ssh_options[@]}" "$normalizer" "$remote:$remote_normalizer"

ssh "${ssh_options[@]}" "$remote" bash -s -- \
    "$revision" \
    "${expected_revision:-none}" \
    "$STAGING_ROOT" \
    "$STAGING_PROJECT" \
    "$STAGING_HEALTH_URL" \
    "$STAGING_EXPECTED_HOST_PORT" \
    "$remote_archive" \
    "$remote_normalizer" <<'REMOTE'
set -Eeuo pipefail

report_preflight_failure() {
    local status="$?"
    rm -f -- "${remote_normalizer:-}" || true
    echo "Staging deployment failed during preflight at remote script line $1 (exit $status)." >&2
    exit "$status"
}

trap 'report_preflight_failure "$LINENO"' ERR

revision="$1"
expected_revision="$2"
if [[ "$expected_revision" == "none" ]]; then
    expected_revision=""
fi
root="$3"
project="$4"
health_url="$5"
expected_host_port="$6"
archive="$7"
remote_normalizer="$8"
queue_probe_script=''
candidate_build_cache=''
current_probe_environment=''
candidate_probe_environment=''
queue_preflight_cache=''
staging_network=''
redis_volume_override=''
current_redis_volume=''
current_redis_volume_key=''
candidate_redis_volume_key=''
current_compose_base=()
current_compose=()
candidate_compose_base=()
candidate_compose=()
runtime_compose=()

cleanup_remote_transfer_artifacts() {
    rm -f -- "$archive" "$remote_normalizer" || true
    rm -f -- "$queue_probe_script" || true
    rm -f -- "$current_probe_environment" "$candidate_probe_environment" || true
    rm -f -- "$redis_volume_override" || true
    if [[ -n "$candidate_build_cache" ]]; then
        rm -rf -- "$candidate_build_cache" || true
    fi
    if [[ -n "$queue_preflight_cache" ]]; then
        rm -rf -- "$queue_preflight_cache" || true
    fi
}
trap cleanup_remote_transfer_artifacts EXIT

release="$root/releases/$revision"
compose="$root/compose.yml"
environment="$root/shared/.env"
backups="$root/shared/backups"
current_revision="$(cat "$root/REVISION")"
previous_target="$(readlink -f "$root/current")"
snapshot="$backups/predeploy-$revision"
database_backup="$backups/postgresql-before-$revision.dump"
compose_backup="$backups/compose-before-$revision.yml"

assert_redis_queue_environment() {
    local queue_connection_count
    queue_connection_count="$(grep -Ec '^QUEUE_CONNECTION=' "$environment" || true)"

    if [[ "$queue_connection_count" -ne 1 ]] || ! grep -Fxq 'QUEUE_CONNECTION=redis' "$environment"; then
        echo "Staging application environment must explicitly set QUEUE_CONNECTION=redis." >&2
        return 1
    fi
}

compose_service_environment_file() {
    local compose_file="$1"
    local service="$2"
    local volume_override="${3:-}"
    local output
    local -a compose_arguments=(docker compose --project-name "$project" --env-file "$environment" -f "$compose_file")

    if [[ -n "$volume_override" ]]; then
        compose_arguments+=(-f "$volume_override")
    fi

    output="$(mktemp)"
    chmod 0600 "$output"
    if ! cat "$environment" > "$output"; then
        rm -f -- "$output"
        echo "Could not read the staging application environment." >&2
        return 1
    fi
    printf '\n' >> "$output"
    if ! "${compose_arguments[@]}" config --format json 2>/dev/null \
        | jq -r --arg service "$service" '
            .services[$service].environment // {}
            | if type != "object" then error("service environment is not an object") else . end
            | to_entries
            | if any(.[]; .value == null) then error("service environment contains an unresolved value") else .[] | "\(.key)=\(.value | tostring)" end
        ' >> "$output"; then
        rm -f -- "$output"
        echo "Could not resolve the staging $service service environment." >&2
        return 1
    fi

    printf '%s\n' "$output"
}

resolve_current_redis_volume() {
    local container redis_container_output redis_container_count compose_config
    local mounts_json data_mount_count named_data_mount_count

    if ! redis_container_output="$("${current_compose_base[@]}" ps --status running -q redis)"; then
        echo "Could not resolve the current staging Redis container; refusing cold initialization." >&2
        return 1
    fi
    redis_container_count="$(awk 'NF { count += NF } END { print count + 0 }' <<< "$redis_container_output")"
    if [[ "$redis_container_count" -ne 1 ]]; then
        echo "Could not resolve exactly one running staging Redis container; refusing cold initialization." >&2
        return 1
    fi
    container="$(awk 'NF { print $1; exit }' <<< "$redis_container_output")"

    if ! mounts_json="$(docker inspect "$container" --format '{{json .Mounts}}')"; then
        echo "Could not inspect the current staging Redis container mounts; refusing cold initialization." >&2
        return 1
    fi
    if ! jq -e 'type == "array"' <<< "$mounts_json" > /dev/null; then
        echo "Current staging Redis mount evidence is invalid; refusing cold initialization." >&2
        return 1
    fi

    data_mount_count="$(jq -er '[.[] | select(.Destination == "/data")] | length' <<< "$mounts_json")"
    named_data_mount_count="$(jq -er '[.[] | select(.Destination == "/data" and .Type == "volume" and (.Name | type == "string") and (.Name | length > 0) and (.Source | type == "string") and (.Source | length > 0))] | length' <<< "$mounts_json")"
    if [[ "$data_mount_count" -ne 1 || "$named_data_mount_count" -ne 1 ]]; then
        echo "Current staging Redis must have exactly one named volume mounted at /data; refusing cold initialization." >&2
        return 1
    fi

    current_redis_volume="$(jq -er '[.[] | select(.Destination == "/data" and .Type == "volume" and (.Name | type == "string") and (.Name | length > 0) and (.Source | type == "string") and (.Source | length > 0))][0].Name' <<< "$mounts_json")"
    if [[ ! "$current_redis_volume" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
        echo "Current staging Redis physical volume name is invalid; refusing cold initialization." >&2
        return 1
    fi
    if ! docker volume inspect "$current_redis_volume" > /dev/null 2>&1; then
        echo "Current staging Redis physical volume is missing; refusing cold initialization." >&2
        return 1
    fi

    if ! compose_config="$("${current_compose_base[@]}" config --format json 2>/dev/null)"; then
        echo "Could not resolve the current staging Redis Compose configuration; refusing cold initialization." >&2
        return 1
    fi
    if ! current_redis_volume_key="$(jq -er '
        [.services.redis.volumes[]?
            | select(.target == "/data" and .type == "volume" and (.source | type == "string") and (.source | length > 0))
            | .source
        ]
        | if length == 1 then .[0] else error("expected exactly one Redis /data volume key") end
    ' <<< "$compose_config")"; then
        echo "Current staging Redis Compose configuration must expose exactly one named /data volume key; refusing cold initialization." >&2
        return 1
    fi
    if [[ ! "$current_redis_volume_key" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
        echo "Current staging Redis Compose volume key is invalid; refusing cold initialization." >&2
        return 1
    fi

    echo "Current Redis /data physical volume: $current_redis_volume"
}

write_redis_volume_override() {
    local additional_volume_key="${1:-}"
    local volume_key
    local -a volume_keys=("$current_redis_volume_key")

    if [[ -z "$current_redis_volume" ]]; then
        echo "Current staging Redis physical volume was not resolved; refusing cold initialization." >&2
        return 1
    fi
    if [[ -z "$current_redis_volume_key" ]]; then
        echo "Current staging Redis Compose volume key was not resolved; refusing cold initialization." >&2
        return 1
    fi
    if [[ -n "$additional_volume_key" && "$additional_volume_key" != "$current_redis_volume_key" ]]; then
        volume_keys+=("$additional_volume_key")
    fi
    for volume_key in "${volume_keys[@]}"; do
        if [[ ! "$volume_key" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
            echo "Staging Redis Compose volume key is invalid; refusing cold initialization." >&2
            return 1
        fi
    done

    if [[ -z "$redis_volume_override" ]]; then
        redis_volume_override="$(mktemp)"
    fi
    chmod 0600 "$redis_volume_override"
    {
        printf 'volumes:\n'
        for volume_key in "${volume_keys[@]}"; do
            printf '  %s:\n    name: "%s"\n' "$volume_key" "$current_redis_volume"
        done
    } > "$redis_volume_override"
}

resolve_candidate_redis_volume_key() {
    local candidate_config

    if ! candidate_config="$("${candidate_compose[@]}" config --format json 2>/dev/null)"; then
        echo "Could not resolve the candidate Redis Compose configuration; refusing activation." >&2
        return 1
    fi
    if ! candidate_redis_volume_key="$(jq -er '
        [.services.redis.volumes[]?
            | select(.target == "/data" and .type == "volume" and (.source | type == "string") and (.source | length > 0))
            | .source
        ]
        | if length == 1 then .[0] else error("expected exactly one Redis /data volume key") end
    ' <<< "$candidate_config")"; then
        echo "Candidate Redis Compose configuration must expose exactly one named /data volume key; refusing activation." >&2
        return 1
    fi
    if [[ ! "$candidate_redis_volume_key" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
        echo "Candidate Redis Compose volume key is invalid; refusing activation." >&2
        return 1
    fi
}

verify_candidate_redis_volume() {
    local candidate_config

    if ! candidate_config="$("${candidate_compose[@]}" config --format json 2>/dev/null)"; then
        echo "Could not resolve the candidate Redis Compose configuration." >&2
        return 1
    fi
    if ! jq -e --arg expected "$current_redis_volume" --arg volume_key "$candidate_redis_volume_key" '
        (.volumes[$volume_key].name // "") == $expected
        and ((.services.redis.volumes // []) | map(select(.target == "/data")) | length == 1)
        and ((.services.redis.volumes // []) | map(select(.target == "/data" and .type == "volume" and .source == $volume_key)) | length == 1)
    ' <<< "$candidate_config" > /dev/null; then
        echo "Candidate Redis /data physical volume does not match the current staging volume; refusing activation." >&2
        return 1
    fi

    echo "Candidate Redis /data physical volume: $current_redis_volume"
}

ensure_current_dependencies() {
    "${current_compose[@]}" up -d postgres redis < /dev/null
    "${current_compose[@]}" up -d --wait postgres redis < /dev/null
}

resolve_staging_network() {
    local container network label
    local networks

    container="$("${current_compose[@]}" ps --status running -q redis)"
    if [[ -z "$container" ]]; then
        echo "Could not resolve the current staging Redis container." >&2
        return 1
    fi

    networks="$(docker inspect "$container" --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}')"
    staging_network=''
    while IFS= read -r network; do
        [[ -n "$network" ]] || continue
        label="$(docker network inspect "$network" --format '{{index .Labels "com.docker.compose.project"}}' 2>/dev/null || true)"
        if [[ "$label" == "$project" ]]; then
            if [[ -n "$staging_network" ]]; then
                echo "Current Redis is attached to multiple staging Compose networks." >&2
                return 1
            fi
            staging_network="$network"
        fi
    done <<< "$networks"

    if [[ -z "$staging_network" ]]; then
        echo "Could not resolve the current staging Compose network from Redis." >&2
        return 1
    fi
}

run_queue_contract_probe() {
    local check="$1"
    local source="$2"
    local image="$3"
    local cache="$4"
    local probe_environment="$5"
    local output

    if ! output="$(docker run --rm --network "$staging_network" --env-file "$probe_environment" \
        --env 'APP_CONFIG_CACHE=/app/bootstrap/cache/config.php' \
        -v "$source:/app:ro" \
        -v "$root/shared/storage:/app/storage:ro" \
        -v "$cache:/app/bootstrap/cache:ro" \
        -w /app "$image" php -- --check="$check" < "$queue_probe_script" 2>&1)"; then
        echo "Laravel queue Redis preflight failed." >&2
        return 1
    fi

    output="$(printf '%s\n' "$output" | grep -E '^B2B_QUEUE_PROBE=' | tail -n 1 || true)"
    if [[ ! "$output" =~ ^B2B_QUEUE_PROBE=\{.*\}$ ]]; then
        echo "Laravel queue Redis preflight returned no valid probe result." >&2
        return 1
    fi

    printf '%s\n' "${output#B2B_QUEUE_PROBE=}"
}

write_normalized_nftables() {
    local output="$1" nftables_dump loopback_guard_count
    local service container service_ip escaped_service_ip
    local -a service_ips=()

    for service in postgres redis app horizon scheduler telegram; do
        container="$("${current_compose[@]}" ps -aq "$service")"
        if [[ -z "$container" ]]; then
            echo "Could not resolve the isolated staging $service container." >&2
            return 1
        fi
        service_ip="$(docker inspect "$container" --format '{{range .NetworkSettings.Networks}}{{println .IPAddress}}{{end}}' | awk 'NF { print; exit }')"

        if [[ ! "$service_ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
            echo "Could not resolve the isolated staging $service container IP." >&2
            return 1
        fi

        service_ips+=("$service_ip")
    done

    nftables_dump="$(mktemp)"
    nft list ruleset > "$nftables_dump"
    loopback_guard_count="$(grep -Ec 'ip daddr 127\.0\.0\.1 iifname != "lo" tcp dport 18080 .* drop$' "$nftables_dump" || true)"

    if [[ "$loopback_guard_count" -ne 1 ]]; then
        echo "Expected exactly one loopback guard for the isolated staging app port." >&2
        rm -f "$nftables_dump"
        return 1
    fi

    sed -E '/ip daddr 127\.0\.0\.1 iifname != "lo" tcp dport 18080 .* drop$/d' "$nftables_dump" \
        | sed -E 's/counter packets [0-9]+ bytes [0-9]+/counter packets N bytes N/g' \
        | awk -f "$remote_normalizer" > "$output"
    printf '%s\n' 'CHUKLOV_LOOPBACK_GUARD_PRESENT' >> "$output"
    rm -f "$nftables_dump"

    for service_ip in "${service_ips[@]}"; do
        escaped_service_ip="${service_ip//./\.}"
        sed -i "s/$escaped_service_ip/CHUKLOV_CONTAINER_IP/g" "$output"
    done
}

if [[ -n "$expected_revision" && "$current_revision" != "$expected_revision" ]]; then
    echo "Remote revision mismatch: expected $expected_revision, found $current_revision" >&2
    exit 1
fi

assert_redis_queue_environment

if [[ "$current_revision" == "$revision" ]]; then
    echo "Revision $revision is already deployed."
    exit 0
fi

cd "$root"
current_compose_base=(docker compose --project-name "$project" --env-file "$environment" -f "$compose")
resolve_current_redis_volume
write_redis_volume_override
current_compose=("${current_compose_base[@]}" -f "$redis_volume_override")
"${current_compose[@]}" config --quiet
ensure_current_dependencies
resolve_staging_network

current_image="$("${current_compose[@]}" config --format json \
    | jq -er '.services.app.image // empty')"
current_probe_environment="$(compose_service_environment_file "$compose" app "$redis_volume_override")"
queue_probe_script="$(mktemp)"
if ! tar -xOf "$archive" scripts/staging-smoke.php > "$queue_probe_script"; then
    echo "Candidate queue preflight script is missing from the release archive." >&2
    exit 1
fi
if [[ ! -d "$root/shared/bootstrap-cache" ]]; then
    echo "Current staging configuration cache directory is missing." >&2
    exit 1
fi

if [[ -e "$release" ]]; then
    if [[ "$release" == "$root/releases/$revision"
        && "$previous_target" != "$release"
        && "$(cat "$release/REVISION" 2>/dev/null || true)" == "$revision" ]]; then
        rm -rf -- "$release"
    else
        echo "Release already exists and is not safe to replace: $release" >&2
        exit 1
    fi
fi

install -d -m 0700 "$snapshot"
install -d -m 0700 "$backups"
date --iso-8601=seconds > "$snapshot/captured-at.txt"
ss -H -lntup | sort > "$snapshot/listening-ports-full.txt"
ss -H -lntup | sed -E 's/pid=[0-9]+/pid=PID/g; s/fd=[0-9]+/fd=FD/g; s/[[:space:]]+$//' | sort > "$snapshot/listening-ports.txt"
systemctl list-units --type=service --state=running --no-pager > "$snapshot/running-services.txt"
pm2 jlist > "$snapshot/pm2.json"
pm2 jlist | jq -r '.[] | [.name, .pm2_env.status] | @tsv' | sort > "$snapshot/pm2-status.tsv"
nginx -T > "$snapshot/nginx-effective.txt" 2>&1
write_normalized_nftables "$snapshot/nftables.txt"
docker ps --format '{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}' | sort > "$snapshot/docker.txt"
sudo -u postgres psql -Atqc 'select datname from pg_database order by datname' > "$snapshot/host-databases.txt"
chmod 0600 "$snapshot"/*
echo "Pre-deploy host baseline captured."

cd "$root"
"${current_compose[@]}" config --quiet

actual_binding="$("${current_compose[@]}" port app 8000)"
if [[ "$actual_binding" != "$expected_host_port" ]]; then
    echo "Unexpected app binding: $actual_binding" >&2
    exit 1
fi

for service in postgres redis app horizon scheduler telegram; do
    "${current_compose[@]}" config --services | grep -Fxq "$service"
done
echo "Isolated Compose project and app binding verified."

"${current_compose[@]}" exec -T postgres \
    sh -lc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' < /dev/null > "$database_backup"
"${current_compose[@]}" exec -T postgres \
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
candidate_build_cache="$(mktemp -d)"
docker run --rm --env-file "$environment" \
    -v "$release:/app" \
    -v "$root/shared/storage:/app/storage:ro" \
    -v "$candidate_build_cache:/app/bootstrap/cache" \
    -w /app "chuklov-staging-app:$revision" \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
docker run --rm -v "$release:/app" -w /app node:24.6.0-alpine npm ci --ignore-scripts
docker run --rm -v "$release:/app" -w /app node:24.6.0-alpine npm run build

prepare_release_permissions() {
    chown -R root:33 "$release"
    find "$release" -type d -exec chmod 0550 {} +
    find "$release" -type f -exec chmod 0440 {} +
}

prepare_release_permissions

sed -E "s#chuklov-staging-app:[0-9a-f]{40}#chuklov-staging-app:$revision#g" "$compose_backup" > "$compose.next"

normalize_legacy_app_server_command() {
    local source="$1"
    local target="$2"

    awk '
        /^  [^[:space:]][^:]*:/ {
            in_app = ($0 == "  app:")
            skipping_command = 0
        }

        in_app && /^    command:/ {
            skipping_command = ($0 ~ /^    command:[[:space:]]*$/)
            next
        }

        in_app && skipping_command && /^    [^[:space:]]/ {
            skipping_command = 0
        }

        in_app && skipping_command {
            next
        }

        { print }
    ' "$source" > "$target"
}

if grep -Fq 'php -S' "$compose.next"; then
    normalized_compose="$compose.next.normalized"
    normalize_legacy_app_server_command "$compose.next" "$normalized_compose"
    mv "$normalized_compose" "$compose.next"
    echo "Removed legacy php -S app command from the staging Compose configuration."
fi

if grep -Fq 'php -S' "$compose.next"; then
    echo "Staging Compose configuration still contains the forbidden php -S app command." >&2
    exit 1
fi

normalize_staging_runtime_user() {
    local source="$1"
    local target="$2"

    awk '
        /^x-app-base:/ {
            in_app_base = 1
            runtime_user_written = 0
            print
            next
        }

        in_app_base && /^  image:/ {
            print
            if (!runtime_user_written) {
                print "  user: \"33:33\""
                runtime_user_written = 1
            }
            next
        }

        in_app_base && /^  user:/ {
            if (!runtime_user_written) {
                print "  user: \"33:33\""
                runtime_user_written = 1
            }
            next
        }

        in_app_base && /^[^[:space:]]/ {
            in_app_base = 0
        }

        { print }
    ' "$source" > "$target"
}

if ! grep -Eq '^x-app-base:' "$compose.next"; then
    echo "Staging Compose configuration is missing the hardened app base." >&2
    exit 1
fi
normalized_compose="$compose.next.runtime-user"
normalize_staging_runtime_user "$compose.next" "$normalized_compose"
mv "$normalized_compose" "$compose.next"

candidate_compose_base=(docker compose --project-name "$project" --env-file "$environment" -f "$compose.next")
candidate_compose=("${candidate_compose_base[@]}" -f "$redis_volume_override")
resolve_candidate_redis_volume_key
write_redis_volume_override "$candidate_redis_volume_key"
verify_candidate_redis_volume
"${candidate_compose[@]}" config --quiet
chmod 0640 "$compose.next"
"${candidate_compose[@]}" config --format json \
    | jq -e --arg image "chuklov-staging-app:$revision" \
        '[.services.app, .services.horizon, .services.scheduler, .services.telegram] | all(.image == $image and .user == "33:33")' > /dev/null
candidate_probe_environment="$(compose_service_environment_file "$compose.next" app "$redis_volume_override")"

current_queue_probe="$(run_queue_contract_probe "queue-snapshot" "$previous_target" "$current_image" "$root/shared/bootstrap-cache" "$current_probe_environment")"
current_queue_fingerprint="$(jq -er '.fingerprint | select(type == "string" and test("^[0-9a-f]{64}$"))' <<< "$current_queue_probe")"
current_pending_work="$(jq -er '
    if (.counts | type) != "object" then error("missing counts")
    elif ([.counts.pending, .counts.delayed, .counts.reserved] | all(type == "number" and . >= 0 and floor == .))
    then (.counts.pending + .counts.delayed + .counts.reserved)
    else error("invalid counts")
    end
' <<< "$current_queue_probe")"
echo "Current Laravel queue Redis contract verified with $current_pending_work pending B2B work item(s)."

verify_queue_contract() {
    queue_preflight_cache="$(mktemp -d)"
    local output candidate_queue_probe candidate_queue_fingerprint

    if ! output="$(docker run --rm --network "$staging_network" --env-file "$candidate_probe_environment" \
        --env 'APP_CONFIG_CACHE=/app/bootstrap/cache/config.php' \
        -v "$release:/app:ro" \
        -v "$root/shared/storage:/app/storage:ro" \
        -v "$queue_preflight_cache:/app/bootstrap/cache" \
        -w /app "chuklov-staging-app:$revision" \
        php artisan config:cache --no-ansi < /dev/null 2>&1)"; then
        echo "Candidate cached configuration generation failed." >&2
        return 1
    fi
    if [[ ! -s "$queue_preflight_cache/config.php" ]]; then
        echo "Candidate configuration cache was not generated in the disposable cache directory." >&2
        return 1
    fi

    candidate_queue_probe="$(run_queue_contract_probe "queue-contract" "$release" "chuklov-staging-app:$revision" "$queue_preflight_cache" "$candidate_probe_environment")"
    if ! candidate_queue_fingerprint="$(jq -er '.fingerprint | select(type == "string" and test("^[0-9a-f]{64}$"))' <<< "$candidate_queue_probe")"; then
        echo "Candidate Laravel queue Redis preflight returned an invalid physical identity." >&2
        return 1
    fi

    if [[ "$current_pending_work" -gt 0 && "$candidate_queue_fingerprint" != "$current_queue_fingerprint" ]]; then
        echo "Pending B2B work would be stranded by a physical Redis queue identity change." >&2
        return 1
    fi

    rm -rf -- "$queue_preflight_cache"
    queue_preflight_cache=''
    echo "Candidate Laravel queue Redis contract and physical identity preflight passed."
}

verify_queue_contract

rollback() {
    local failed_line="${1:-unknown}"
    local failed_status="${2:-1}"
    local -a rollback_compose=(docker compose --project-name "$project" --env-file "$environment" -f "$compose")

    if [[ -n "$redis_volume_override" ]]; then
        rollback_compose+=(-f "$redis_volume_override")
    fi

    echo "Deployment failed at post-switch line $failed_line (exit $failed_status); restoring application release $current_revision." >&2
    ln -s "$previous_target" "$root/current.rollback"
    mv -Tf "$root/current.rollback" "$root/current"
    cp "$compose_backup" "$compose"
    cd "$root"
    "${rollback_compose[@]}" up -d --no-deps --force-recreate app horizon scheduler telegram < /dev/null || true
    rm -f -- "$remote_normalizer" || true
}
trap 'rollback "$LINENO" "$?"' ERR

mv "$compose.next" "$compose"
ln -s "$release" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"
runtime_compose=(docker compose --project-name "$project" --env-file "$environment" -f "$compose" -f "$redis_volume_override")

"${runtime_compose[@]}" run --rm --no-deps app php artisan migrate --force < /dev/null
"${runtime_compose[@]}" run --rm --no-deps app php artisan portal:validate-configuration < /dev/null
"${runtime_compose[@]}" run --rm --no-deps app php artisan db:seed --class=Database\\Seeders\\ScenarioNotificationSeeder --force < /dev/null
"${runtime_compose[@]}" run --rm --no-deps app php artisan optimize < /dev/null
"${runtime_compose[@]}" run --rm --no-deps app php artisan filament:optimize < /dev/null

prepare_runtime_ownership() {
    local runtime_path

    for runtime_path in \
        "$root/shared/storage/app/private" \
        "$root/shared/storage/app/public" \
        "$root/shared/storage/framework/cache/data" \
        "$root/shared/storage/framework/sessions" \
        "$root/shared/storage/framework/views" \
        "$root/shared/storage/inertia-devtools" \
        "$root/shared/storage/logs" \
        "$root/shared/bootstrap-cache"; do
        install -d -m 0770 -o 33 -g 33 "$runtime_path"
    done

    chown -R 33:33 "$root/shared/storage" "$root/shared/bootstrap-cache"
    chmod 0711 \
        "$root/shared/storage" \
        "$root/shared/storage/app" \
        "$root/shared/storage/framework" \
        "$root/shared/storage/framework/cache" \
        "$root/shared/bootstrap-cache"
}

prepare_runtime_ownership
"${runtime_compose[@]}" up -d postgres redis < /dev/null
"${runtime_compose[@]}" up -d --no-deps --force-recreate app horizon scheduler telegram < /dev/null
"${runtime_compose[@]}" up -d --wait < /dev/null

echo "Runtime containers refreshed; verifying application health."
curl --noproxy '*' --fail --silent --show-error --retry 15 --retry-delay 2 "$health_url"
for attempt in $(seq 1 15); do
    horizon_status="$("${runtime_compose[@]}" exec -T app php artisan horizon:status --no-ansi < /dev/null 2>&1 || true)"
    horizon_supervisors="$("${runtime_compose[@]}" exec -T app php artisan horizon:supervisors --no-ansi < /dev/null 2>&1 || true)"
    if grep -Fq 'Horizon is running.' <<< "$horizon_status" \
        && grep -Fq 'supervisor-1' <<< "$horizon_supervisors" \
        && grep -Eq '\([1-9][0-9]*\)' <<< "$horizon_supervisors"; then
        break
    fi

    if [[ "$attempt" -eq 15 ]]; then
        echo "Horizon did not report an active supervisor with workers before the verification deadline." >&2
        false
    fi

    sleep 2
done
for service in app horizon scheduler telegram; do
    if ! "${runtime_compose[@]}" ps --status running --services | grep -Fxq "$service"; then
        echo "Required runtime service is not running: $service" >&2
        false
    fi
done
echo "Application, Horizon, scheduler, and Telegram runtime are healthy."

nginx -t
for service in nginx pm2-root postgresql@16-main webstore-darimiru docker; do
    if ! systemctl is-active --quiet "$service"; then
        echo "Protected system service is not active: $service" >&2
        false
    fi
done
if ! cmp -s "$snapshot/nginx-effective.txt" <(nginx -T 2>&1); then
    echo "Protected nginx configuration changed during deployment." >&2
    false
fi
write_normalized_nftables "$snapshot/nftables.after.txt"
if ! cmp -s "$snapshot/nftables.txt" "$snapshot/nftables.after.txt"; then
    echo "Protected nftables rules changed during deployment." >&2
    false
fi
if ! cmp -s "$snapshot/host-databases.txt" <(sudo -u postgres psql -Atqc 'select datname from pg_database order by datname'); then
    echo "Host PostgreSQL database inventory changed during deployment." >&2
    false
fi
if ! cmp -s "$snapshot/listening-ports.txt" <(ss -H -lntup | sed -E 's/pid=[0-9]+/pid=PID/g; s/fd=[0-9]+/fd=FD/g; s/[[:space:]]+$//' | sort); then
    echo "Host listening-port inventory changed during deployment." >&2
    false
fi
if ! cmp -s "$snapshot/pm2-status.tsv" <(pm2 jlist | jq -r '.[] | [.name, .pm2_env.status] | @tsv' | sort); then
    echo "PM2 process status changed during deployment." >&2
    false
fi
echo "Protected host services and routing match the pre-deploy baseline."

printf '%s\n' "$revision" > "$root/REVISION"
chmod -R a-w "$release"
rm -f "$archive"
rm -f -- "$remote_normalizer"
trap - ERR

echo
echo "Successfully deployed $revision"
"${runtime_compose[@]}" ps
REMOTE
