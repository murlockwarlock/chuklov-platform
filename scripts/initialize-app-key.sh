#!/usr/bin/env sh

set -eu

environment_file="${APP_KEY_FILE:-.env}"

if [ ! -f "$environment_file" ]; then
    echo "Application environment file is missing: $environment_file" >&2
    exit 1
fi

application_key="$(sed -n 's/^APP_KEY=//p' "$environment_file" | head -n 1 | tr -d '[:space:]')"

if [ -n "$application_key" ] && [ "$application_key" != '""' ] && [ "$application_key" != "''" ]; then
    exit 0
fi

exec "$@"
