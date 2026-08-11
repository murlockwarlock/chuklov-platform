#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_environment_file="$repository_root/.env.key-idempotence"

cleanup() {
    rm -f "$test_environment_file"
}

trap cleanup EXIT

cp "$repository_root/.env.example" "$test_environment_file"

APP_KEY_FILE="$test_environment_file" \
    "$repository_root/scripts/initialize-app-key.sh" \
    php "$repository_root/artisan" key:generate --env=key-idempotence --no-interaction

first_fingerprint="$(php -r '
$lines = file($argv[1], FILE_IGNORE_NEW_LINES);
$line = array_values(array_filter($lines, static fn (string $value): bool => str_starts_with($value, "APP_KEY=")))[0] ?? "";
$key = trim(substr($line, strlen("APP_KEY=")), " \t\n\r\0\x0B\"");
exit($key === "" ? 1 : fwrite(STDOUT, hash("sha256", $key)) < 1);
' "$test_environment_file")"

APP_KEY_FILE="$test_environment_file" \
    "$repository_root/scripts/initialize-app-key.sh" \
    php "$repository_root/artisan" key:generate --env=key-idempotence --no-interaction

second_fingerprint="$(php -r '
$lines = file($argv[1], FILE_IGNORE_NEW_LINES);
$line = array_values(array_filter($lines, static fn (string $value): bool => str_starts_with($value, "APP_KEY=")))[0] ?? "";
$key = trim(substr($line, strlen("APP_KEY=")), " \t\n\r\0\x0B\"");
exit($key === "" ? 1 : fwrite(STDOUT, hash("sha256", $key)) < 1);
' "$test_environment_file")"

if [ "$first_fingerprint" != "$second_fingerprint" ]; then
    echo 'Application key changed during repeated initialization.' >&2
    exit 1
fi

echo 'Application key idempotence check passed.'
