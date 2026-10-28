#!/usr/bin/env bash
set -Eeuo pipefail

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository_root="$(cd "$script_directory/.." && pwd)"
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

revision="${1:-}"
expected_site_hash="${2:-}"
expected_log_format_hash="${3:-}"
deployment_ref="${STAGING_DEPLOY_REF:-origin/main}"

if [[ ! "$revision" =~ ^[0-9a-f]{40}$ || ! "$expected_site_hash" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Usage: scripts/deploy-staging-nginx-config.sh <full-revision> <expected-site-sha256> <expected-log-format-sha256|absent>" >&2
    exit 1
fi

if [[ "$expected_log_format_hash" != "absent" && ! "$expected_log_format_hash" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Expected log-format state must be 'absent' or a SHA-256 digest." >&2
    exit 1
fi

if [[ "$STAGING_ROOT" != "/opt/chuklov-staging" ]]; then
    echo "Nginx configuration deployment is restricted to /opt/chuklov-staging." >&2
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
transfer_id="$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
site_configuration="$temporary_directory/chuklov-staging-tls.conf"
log_format_configuration="$temporary_directory/00-medical-attachment-log-format.conf"
trap 'rm -rf -- "$temporary_directory"' EXIT

git show "$revision:docker/host-nginx/chuklov-staging-tls.conf" > "$site_configuration"
git show "$revision:docker/host-nginx/00-medical-attachment-log-format.conf" > "$log_format_configuration"

ssh_options=(
    -o BatchMode=yes
    -o ConnectTimeout=15
    -o StrictHostKeyChecking=accept-new
    -i "$STAGING_SSH_KEY"
)
remote="${STAGING_USER}@${STAGING_HOST}"
remote_site="/tmp/chuklov-staging-tls-$transfer_id.conf"
remote_log_format="/tmp/chuklov-medical-log-format-$transfer_id.conf"

scp "${ssh_options[@]}" "$site_configuration" "$remote:$remote_site"
scp "${ssh_options[@]}" "$log_format_configuration" "$remote:$remote_log_format"

ssh "${ssh_options[@]}" "$remote" bash -s -- \
    "$revision" \
    "$expected_site_hash" \
    "$expected_log_format_hash" \
    "$STAGING_ROOT" \
    "$remote_site" \
    "$remote_log_format" \
    "$transfer_id" <<'REMOTE'
set -Eeuo pipefail

revision="$1"
expected_site_hash="$2"
expected_log_format_hash="$3"
root="$4"
site_source="$5"
log_format_source="$6"
transfer_id="$7"
site_target='/etc/nginx/conf.d/chuklov-staging-tls.conf'
log_format_target='/etc/nginx/conf.d/00-medical-attachment-log-format.conf'
backup_directory="$root/shared/backups/nginx-config-$revision-$transfer_id"
rollback_needed=0

restore_configuration() {
    local backup_site="$backup_directory/chuklov-staging-tls.conf"
    local backup_log_format="$backup_directory/00-medical-attachment-log-format.conf"

    if sudo -n test -f "$backup_site"; then
        sudo -n install -o root -g root -m 0644 "$backup_site" "$site_target.$transfer_id" || return 1
        sudo -n mv -fT -- "$site_target.$transfer_id" "$site_target" || return 1
    else
        sudo -n rm -f -- "$site_target" || return 1
    fi

    if sudo -n test -f "$backup_log_format"; then
        sudo -n install -o root -g root -m 0644 "$backup_log_format" "$log_format_target.$transfer_id" || return 1
        sudo -n mv -fT -- "$log_format_target.$transfer_id" "$log_format_target" || return 1
    else
        sudo -n rm -f -- "$log_format_target" || return 1
    fi

    sudo -n nginx -t > /dev/null 2>&1 || return 1
    sudo -n systemctl reload nginx
}

handle_exit() {
    local status="$?"
    trap - EXIT

    if [[ "$status" -ne 0 && "$rollback_needed" == '1' ]]; then
        if restore_configuration; then
            echo "Nginx configuration deployment failed; prior host configuration was restored." >&2
        else
            echo "Nginx configuration deployment failed and automatic host rollback needs operator attention." >&2
        fi
    fi

    sudo -n rm -f -- "$site_target.$transfer_id" "$log_format_target.$transfer_id" || true
    rm -f -- "$site_source" "$log_format_source" || true
    exit "$status"
}
trap handle_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

if [[ "$root" != '/opt/chuklov-staging' || ! "$revision" =~ ^[0-9a-f]{40}$ || ! "$transfer_id" =~ ^[A-Za-z0-9-]+$ ]]; then
    echo 'Nginx configuration deployment arguments are invalid.' >&2
    exit 1
fi

if ! sudo -n test -f "$site_target" || sudo -n test -L "$site_target"; then
    echo 'Current staging Nginx site configuration is missing or is not a regular file.' >&2
    exit 1
fi
current_site_hash="$(sudo -n sha256sum "$site_target" | awk '{print $1}')"
if [[ "$current_site_hash" != "$expected_site_hash" ]]; then
    echo 'Current staging Nginx site configuration differs from the reviewed expected version.' >&2
    exit 1
fi

if sudo -n test -L "$log_format_target"; then
    echo 'Current staging Nginx log-format configuration is not a regular file.' >&2
    exit 1
fi
if sudo -n test -f "$log_format_target"; then
    if [[ "$expected_log_format_hash" == 'absent' ]]; then
        echo 'Current staging Nginx log-format configuration exists but was expected to be absent.' >&2
        exit 1
    fi
    current_log_format_hash="$(sudo -n sha256sum "$log_format_target" | awk '{print $1}')"
    if [[ "$current_log_format_hash" != "$expected_log_format_hash" ]]; then
        echo 'Current staging Nginx log-format configuration differs from the reviewed expected version.' >&2
        exit 1
    fi
elif [[ "$expected_log_format_hash" != 'absent' ]]; then
    echo 'Current staging Nginx log-format configuration is absent but was expected to exist.' >&2
    exit 1
fi

if [[ ! -r "$site_source" || ! -r "$log_format_source" ]]; then
    echo 'Transferred staging Nginx configuration is missing.' >&2
    exit 1
fi

sudo -n install -d -o root -g root -m 0700 "$backup_directory"
sudo -n cp -a -- "$site_target" "$backup_directory/chuklov-staging-tls.conf"
if sudo -n test -f "$log_format_target"; then
    sudo -n cp -a -- "$log_format_target" "$backup_directory/00-medical-attachment-log-format.conf"
else
    sudo -n touch "$backup_directory/00-medical-attachment-log-format.was-absent"
fi
sudo -n chmod 0600 "$backup_directory"/*

rollback_needed=1
sudo -n install -o root -g root -m 0644 "$site_source" "$site_target.$transfer_id"
sudo -n mv -fT -- "$site_target.$transfer_id" "$site_target"
sudo -n install -o root -g root -m 0644 "$log_format_source" "$log_format_target.$transfer_id"
sudo -n mv -fT -- "$log_format_target.$transfer_id" "$log_format_target"

sudo -n nginx -t > /dev/null
sudo -n systemctl reload nginx
sudo -n nginx -T 2>&1 | grep -Fq 'client_max_body_size 24m;'
sudo -n nginx -T 2>&1 | grep -Fq 'log_format medical_attachment_safe'
sudo -n nginx -T 2>&1 | grep -Fq 'access_log /var/log/nginx/access.log medical_attachment_safe;'

rollback_needed=0
echo "Staging Nginx configuration deployed from ${revision:0:7}; host configuration backup: $backup_directory"
REMOTE
