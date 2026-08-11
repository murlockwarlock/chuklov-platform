#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
temporary_root="$(mktemp -d)"
image_prefix="chuklov-context-check-$$"
containers=()
images=()

cleanup() {
    for container in "${containers[@]:-}"; do
        docker rm --force "$container" >/dev/null 2>&1 || true
    done

    for image in "${images[@]:-}"; do
        docker image rm --force "$image" >/dev/null 2>&1 || true
    done

    rm -rf "$temporary_root"
}

trap cleanup EXIT

assert_sanitized_context() {
    local context="$1"
    local suffix="$2"
    local image="${image_prefix}-${suffix}"
    local container="${image}-container"
    local archive="${temporary_root}/${suffix}.tar"
    local listing="${temporary_root}/${suffix}.txt"

    images+=("$image")
    containers+=("$container")

    docker build --quiet --no-cache --tag "$image" \
        --file "$context/docker/context-check.Dockerfile" "$context" >/dev/null
    docker create --name "$container" "$image" /context >/dev/null
    docker export --output "$archive" "$container"
    tar -tf "$archive" > "$listing"

    if grep -Eq '^context/(\.git|\.env($|\.)|\.idea|\.claude|\.cursor|\.vscode|docs/product/source-requirements|test-results|playwright-report|storage/(logs|framework/cache|framework/sessions|framework/views)|backups|private-artifacts)(/|$)' "$listing"; then
        echo 'Docker build context contains a prohibited private or local path.' >&2
        return 1
    fi
}

synthetic_context="${temporary_root}/synthetic"
mkdir -p \
    "$synthetic_context/docker/php" \
    "$synthetic_context/docs/product/source-requirements" \
    "$synthetic_context/.idea" \
    "$synthetic_context/test-results" \
    "$synthetic_context/playwright-report" \
    "$synthetic_context/storage/logs" \
    "$synthetic_context/storage/framework/cache" \
    "$synthetic_context/private-artifacts"
cp "$repository_root/.dockerignore" "$synthetic_context/.dockerignore"
cp "$repository_root/docker/context-check.Dockerfile" "$synthetic_context/docker/context-check.Dockerfile"
cp "$repository_root/docker/php/Dockerfile" "$synthetic_context/docker/php/Dockerfile"
touch \
    "$synthetic_context/.env" \
    "$synthetic_context/.idea/context-probe" \
    "$synthetic_context/docs/product/source-requirements/context-probe" \
    "$synthetic_context/test-results/context-probe" \
    "$synthetic_context/playwright-report/context-probe" \
    "$synthetic_context/storage/logs/context-probe" \
    "$synthetic_context/storage/framework/cache/context-probe" \
    "$synthetic_context/private-artifacts/context-probe"

assert_sanitized_context "$synthetic_context" synthetic
assert_sanitized_context "$repository_root" repository

echo 'Docker build context privacy check passed.'
