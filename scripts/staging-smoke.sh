#!/usr/bin/env bash
set -Eeuo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
deploy_env="${STAGING_DEPLOY_ENV:-$repository_root/.env.staging-deploy}"
mode="default"

if [[ "${1:-}" == "--deep" ]]; then
    mode="deep"
elif [[ -n "${1:-}" ]]; then
    echo "Usage: ./scripts/staging-smoke.sh [--deep]" >&2
    exit 2
fi

if [[ ! -f "$deploy_env" ]]; then
    echo "STAGING CONFIG .............. FAIL missing deployment environment" >&2
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
: "${STAGING_SMOKE_USER_ID:?Missing STAGING_SMOKE_USER_ID}"
: "${STAGING_SMOKE_CLIENT_ID:?Missing STAGING_SMOKE_CLIENT_ID}"

if [[ ! "$STAGING_SMOKE_USER_ID" =~ ^[1-9][0-9]*$ || ! "$STAGING_SMOKE_CLIENT_ID" =~ ^[1-9][0-9]*$ ]]; then
    echo "SMOKE IDENTITY .............. FAIL IDs must be positive integers" >&2
    exit 1
fi

cd "$repository_root"
expected_revision="$(git rev-parse HEAD)"
ssh_options=(
    -o BatchMode=yes
    -o ConnectTimeout=15
    -o StrictHostKeyChecking=accept-new
    -i "$STAGING_SSH_KEY"
)
remote="${STAGING_USER}@${STAGING_HOST}"
compose_arguments=(
    docker compose
    --project-name "$STAGING_PROJECT"
    --env-file "$STAGING_ROOT/shared/.env"
    -f "$STAGING_ROOT/compose.yml"
)

ok() {
    printf '%-30s OK%s\n' "$1" "${2:+ $2}"
}

fail() {
    printf '%-30s FAIL %s\n' "$1" "$2" >&2
    exit 1
}

deployed_revision="$(ssh "${ssh_options[@]}" "$remote" cat "$STAGING_ROOT/REVISION")"
if [[ "$deployed_revision" != "$expected_revision" ]]; then
    fail 'STAGING SHA' "expected ${expected_revision:0:7}, deployed ${deployed_revision:0:7}"
fi
ok 'STAGING SHA' "${deployed_revision:0:7}"

health="$(curl --noproxy '*' --fail --silent --show-error "$STAGING_HEALTH_URL")"
if ! jq -e '.status == "ok" and (.checks | to_entries | all(.value == true))' <<< "$health" > /dev/null; then
    fail 'HEALTH' 'health contract is not fully green'
fi
ok 'HEALTH'

running_services="$(ssh "${ssh_options[@]}" "$remote" "${compose_arguments[@]}" ps --status running --services)"
for service in app horizon scheduler postgres redis telegram; do
    if ! grep -Fxq "$service" <<< "$running_services"; then
        fail "${service^^}" 'container is not running'
    fi
done
ok 'APP'
ok 'SCHEDULER'
ok 'TELEGRAM'

run_telegram_api_check() {
    ssh "${ssh_options[@]}" "$remote" bash -s -- "$STAGING_PROJECT" "$STAGING_ROOT" <<'REMOTE_TELEGRAM_API'
project="$1"
root="$2"
compose=(docker compose --project-name "$project" --env-file "$root/shared/.env" -f "$root/compose.yml")

"${compose[@]}" exec -T telegram sh -lc '
        proxy="${HTTPS_PROXY:-${HTTP_PROXY:-}}"
        if [ -z "$proxy" ]; then
            exit 1
        fi
        for attempt in 1 2 3; do
            if curl --silent --show-error --fail --proxy "$proxy" --noproxy "" \
                --connect-timeout 5 --max-time 12 -o /dev/null \
                "https://api.telegram.org/bot${TELEGRAM_TOKEN}/getMe"; then
                exit 0
            fi
            sleep 1
        done
        exit 1
    '
REMOTE_TELEGRAM_API
}

if ! run_telegram_api_check > /dev/null 2>&1; then
    fail 'TELEGRAM API' 'Bot API getMe is unreachable'
fi
ok 'TELEGRAM API'

run_php_check() {
    local service="$1"
    local check="$2"

    ssh "${ssh_options[@]}" "$remote" \
        "${compose_arguments[@]}" exec -T "$service" php -- \
        "--check=$check" \
        "--user-id=$STAGING_SMOKE_USER_ID" \
        "--client-id=$STAGING_SMOKE_CLIENT_ID" \
        < "$repository_root/scripts/staging-smoke.php"
}

app_runtime_output="$(run_php_check app runtime)"
horizon_runtime_output="$(run_php_check horizon runtime)"

extract_queue_fingerprint() {
    local output="$1"
    local fingerprint

    fingerprint="$(grep -E '^B2B_QUEUE_PHYSICAL_FINGERPRINT=[0-9a-f]{64}$' <<< "$output" | tail -n 1 | cut -d= -f2- || true)"
    if [[ ! "$fingerprint" =~ ^[0-9a-f]{64}$ ]]; then
        fail 'QUEUE IDENTITY' 'runtime did not return a valid physical fingerprint'
    fi

    printf '%s\n' "$fingerprint"
}

app_queue_fingerprint="$(extract_queue_fingerprint "$app_runtime_output")"
horizon_queue_fingerprint="$(extract_queue_fingerprint "$horizon_runtime_output")"
if [[ "$app_queue_fingerprint" != "$horizon_queue_fingerprint" ]]; then
    fail 'QUEUE IDENTITY' 'application and Horizon resolve different physical queue targets'
fi
ok 'QUEUE IDENTITY' 'application and Horizon agree'
ok 'APP RUNTIME'
ok 'HORIZON RUNTIME'

for check in crm-home clients client-card sessions survey-definitions survey-attempts knowledge-sources knowledge-inspector portal; do
    run_php_check app "$check"
done

if [[ "$mode" == "deep" ]]; then
    run_php_check app deep
fi

echo
echo 'STAGING SMOKE: PASS'
