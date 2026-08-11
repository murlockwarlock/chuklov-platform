#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
scan_root="$repository_root/test-results/.secret-scan-$$"

mkdir -p "$scan_root"

cleanup() {
    rm -rf "$scan_root"
}

trap cleanup EXIT

(
    cd "$repository_root"
    git ls-files --cached --others --exclude-standard -z \
        | tar --create --file - --null --files-from -
) | tar --extract --file - --directory "$scan_root"

if command -v gitleaks >/dev/null 2>&1; then
    gitleaks git --gitleaks-ignore-path "$repository_root/.gitleaksignore" --redact --no-banner --verbose "$repository_root"
    gitleaks dir --gitleaks-ignore-path "$repository_root/.gitleaksignore" --redact --no-banner --verbose "$scan_root"
    exit 0
fi

docker run --rm \
    --volume "$repository_root:/repo:ro" \
    ghcr.io/gitleaks/gitleaks:v8.30.1 \
    git --gitleaks-ignore-path=/repo/.gitleaksignore --redact --no-banner --verbose /repo

docker run --rm \
    --volume "$scan_root:/scan:ro" \
    --volume "$repository_root/.gitleaksignore:/repo/.gitleaksignore:ro" \
    ghcr.io/gitleaks/gitleaks:v8.30.1 \
    dir --gitleaks-ignore-path=/repo/.gitleaksignore --redact --no-banner --verbose /scan
