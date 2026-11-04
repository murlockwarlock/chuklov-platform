#!/usr/bin/env bash
set -Eeuo pipefail

mode="${1:-}"
root="${2:-/opt/chuklov-staging}"
requested_retention="${3:-}"
requested_failed_grace_hours="${4:-}"

if [[ "$mode" != '--dry-run' && "$mode" != '--cleanup' ]]; then
    echo "Usage: scripts/staging-release-retention.sh --dry-run|--cleanup [root] [retention] [failed-grace-hours]" >&2
    exit 2
fi

if [[ "$root" == '/' || ! -d "$root" ]]; then
    echo "Invalid staging root." >&2
    exit 1
fi

root="$(realpath "$root")"
if [[ ! -d "$root/releases" ]]; then
    echo "Invalid staging releases directory." >&2
    exit 1
fi
release_root="$(realpath "$root/releases")"
if [[ "$release_root" != "$root/releases" ]]; then
    echo "Staging releases directory is not the expected child of the staging root." >&2
    exit 1
fi

retention="${requested_retention:-${STAGING_RELEASE_RETENTION:-5}}"
if [[ ! "$retention" =~ ^[0-9]+$ ]]; then
    echo "Release retention must be a non-negative integer." >&2
    exit 1
fi
measure_estimate="${STAGING_RELEASE_RETENTION_MEASURE:-1}"
if [[ "$measure_estimate" != '0' && "$measure_estimate" != '1' ]]; then
    echo "STAGING_RELEASE_RETENTION_MEASURE must be 0 or 1." >&2
    exit 1
fi
failed_grace_hours="${requested_failed_grace_hours:-${STAGING_FAILED_RELEASE_GRACE_HOURS:-48}}"
if [[ ! "$failed_grace_hours" =~ ^[0-9]+$ ]]; then
    echo "Failed release grace period must be a non-negative integer." >&2
    exit 1
fi
failed_grace_seconds=$((failed_grace_hours * 3600))
now="$(date +%s)"

is_release_name() {
    [[ "$1" =~ ^[0-9a-f]{40}$ ]]
}

is_failed_release_name() {
    [[ "$1" =~ ^[0-9a-f]{40}\.(failed|failed-retry|incomplete)$ ]]
}

is_release_entry_name() {
    is_release_name "$1" || is_failed_release_name "$1"
}

is_old_enough() {
    (( now - "$1" >= failed_grace_seconds ))
}

mtime_of() {
    local path="$1"
    local value

    if value="$(stat -c '%Y' -- "$path" 2>/dev/null)"; then
        printf '%s\n' "$value"
        return
    fi
    stat -f '%m' "$path"
}

release_name_from_target() {
    local target="$1"
    local relative

    case "$target" in
        "$release_root"/*)
            relative="${target#"$release_root/"}"
            if [[ "$relative" != */* ]]; then
                printf '%s\n' "$relative"
            fi
            ;;
    esac
}

contains_name() {
    local needle="$1"
    shift
    local item

    for item in "$@"; do
        [[ "$item" == "$needle" ]] && return 0
    done
    return 1
}

protected_names=()
retained_names=()
eligible_names=()
eligible_mtimes=()
incomplete_names=()
incomplete_mtimes=()
failed_names=()
failed_mtimes=()
release_names=()
release_paths=()
release_mtimes=()
unsafe_entries=()
candidate_names=()
incomplete_candidate_names=()
failed_candidate_names=()
active_name=''

refresh_protected() {
    local link target name

    protected_names=()
    target="$(realpath "$root/current" 2>/dev/null || true)"
    name="$(release_name_from_target "$target")"
    if [[ -n "$name" ]] && is_release_entry_name "$name"; then
        active_name="$name"
        protected_names+=( "$name" )
    else
        echo "Current staging symlink does not resolve to a named release." >&2
        return 1
    fi

    while IFS= read -r -d '' link; do
        [[ -n "$link" ]] || continue
        target="$(realpath "$link" 2>/dev/null || true)"
        name="$(release_name_from_target "$target")"
        if [[ -z "$name" ]] || ! is_release_entry_name "$name"; then
            unsafe_entries+=("${link##*/}: symlink target is outside the release naming contract")
            continue
        fi
        if ! contains_name "$name" "${protected_names[@]}"; then
            protected_names+=( "$name" )
        fi
    done < <(find "$root" -mindepth 1 -maxdepth 1 -type l -print0 | sort -z)
}

is_successful_release() {
    local path="$1"
    local name="$2"

    if [[ -f "$path/.staging-deploy-success" ]] && [[ "$(cat -- "$path/.staging-deploy-success")" == "$name" ]]; then
        return 0
    fi

    [[ -f "$path/REVISION" ]] \
        && [[ "$(cat -- "$path/REVISION")" == "$name" ]] \
        && [[ -f "$path/docker-compose.staging-qa.yml" ]]
}

refresh_protected

while IFS= read -r -d '' path; do
    [[ -n "$path" ]] || continue
    name="${path##*/}"
    if [[ -L "$path" || ! -d "$path" ]]; then
        unsafe_entries+=( "$name: not a real release directory" )
        continue
    fi
    if ! is_release_entry_name "$name"; then
        unsafe_entries+=( "$name: release name is outside the SHA convention" )
        continue
    fi

    release_names+=( "$name" )
    release_paths+=( "$path" )
    release_mtimes+=( "$(mtime_of "$path")" )
    if is_failed_release_name "$name"; then
        failed_names+=( "$name" )
        failed_mtimes+=( "${release_mtimes[$((${#release_mtimes[@]} - 1))]}" )
    elif is_successful_release "$path" "$name"; then
        eligible_names+=( "$name" )
        eligible_mtimes+=( "${release_mtimes[$((${#release_mtimes[@]} - 1))]}" )
    else
        incomplete_names+=( "$name" )
        incomplete_mtimes+=( "${release_mtimes[$((${#release_mtimes[@]} - 1))]}" )
    fi
done < <(find "$release_root" -mindepth 1 -maxdepth 1 -print0 | sort -z)

retained_count=0
while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    if [[ "$name" == "$active_name" ]]; then
        retained_names+=( "$name" )
        continue
    fi
    if (( retained_count < retention )); then
        retained_names+=( "$name" )
        retained_count=$((retained_count + 1))
    elif contains_name "$name" "${protected_names[@]}"; then
        retained_names+=( "$name" )
    fi
done < <(
    for index in "${!eligible_names[@]}"; do
        printf '%s\t%s\n' "${eligible_mtimes[$index]}" "${eligible_names[$index]}"
    done | sort -rn | cut -f2-
)

for name in "${eligible_names[@]}"; do
    if contains_name "$name" "${protected_names[@]}" || contains_name "$name" "${retained_names[@]}"; then
        continue
    fi
    candidate_names+=( "$name" )
done

for index in "${!incomplete_names[@]}"; do
    name="${incomplete_names[$index]}"
    if ! is_old_enough "${incomplete_mtimes[$index]}"; then
        continue
    fi
    if contains_name "$name" "${protected_names[@]}"; then
        continue
    fi
    candidate_names+=( "$name" )
    incomplete_candidate_names+=( "$name" )
done

for index in "${!failed_names[@]}"; do
    name="${failed_names[$index]}"
    if ! is_old_enough "${failed_mtimes[$index]}"; then
        continue
    fi
    if contains_name "$name" "${protected_names[@]}"; then
        continue
    fi
    candidate_names+=( "$name" )
    failed_candidate_names+=( "$name" )
done

candidate_bytes=0
candidate_files=0
if [[ "$mode" == '--dry-run' ]] && (("${#candidate_names[@]}" > 0)); then
    if [[ "$measure_estimate" != '1' ]]; then
        candidate_bytes='not-measured'
        candidate_files='not-measured'
    else
    candidate_paths=()
    for name in "${candidate_names[@]}"; do
        for index in "${!release_names[@]}"; do
            if [[ "${release_names[$index]}" == "$name" ]]; then
                candidate_paths+=( "${release_paths[$index]}" )
                break
            fi
        done
    done
    while read -r blocks path; do
        candidate_bytes=$((candidate_bytes + blocks * 1024))
    done < <(du -sk "${candidate_paths[@]}")
    candidate_files="$(find "${candidate_paths[@]}" -type f -print | wc -l | tr -d ' ')"
    fi
fi

printf 'root=%s\n' "$root"
printf 'active=%s\n' "$(realpath "$root/current")"
printf 'retention=%s\n' "$retention"
printf 'failed_grace_hours=%s\n' "$failed_grace_hours"
printf 'total_release_entries=%s\n' "$((${#release_names[@]} + ${#unsafe_entries[@]}))"
printf 'successful_release_count=%s\n' "${#eligible_names[@]}"
printf 'incomplete_release_count=%s\n' "${#incomplete_names[@]}"
printf 'failed_release_count=%s\n' "${#failed_names[@]}"
printf 'protected_release_count=%s\n' "${#protected_names[@]}"
printf 'retained_release_count=%s\n' "${#retained_names[@]}"
printf 'candidate_count=%s\n' "${#candidate_names[@]}"
printf 'incomplete_candidate_count=%s\n' "${#incomplete_candidate_names[@]}"
printf 'failed_candidate_count=%s\n' "${#failed_candidate_names[@]}"
printf 'candidate_bytes=%s\n' "$candidate_bytes"
printf 'candidate_regular_files=%s\n' "$candidate_files"
printf 'retained=%s\n' "$(printf '%s ' "${retained_names[@]}" | sed 's/[[:space:]]*$//')"
if (("${#unsafe_entries[@]}" > 0)); then
    printf 'unsafe_entries=%s\n' "$(printf '%s; ' "${unsafe_entries[@]}" | sed 's/[[:space:]]*$//')"
fi
if (("${#candidate_names[@]}" > 0)); then
    printf 'candidates=%s\n' "$(printf '%s ' "${candidate_names[@]}" | sed 's/[[:space:]]*$//')"
fi

if ((${#unsafe_entries[@]} > 0)); then
    echo "Refusing staging release retention because unsafe entries were found." >&2
    exit 1
fi

if [[ "$mode" == '--dry-run' ]]; then
    exit 0
fi

failed=0
if (("${#candidate_names[@]}" > 0)); then
    for name in "${candidate_names[@]}"; do
        refresh_protected
        path=''
        for index in "${!release_names[@]}"; do
            if [[ "${release_names[$index]}" == "$name" ]]; then
                path="${release_paths[$index]}"
                break
            fi
        done
        canonical="$(realpath "$path" 2>/dev/null || true)"
        if [[ "$canonical" != "$release_root/$name" \
            || "$path" != "$release_root/$name" \
            || -L "$path" \
            || ! -d "$path" ]]; then
            echo "Refusing unsafe release deletion: $path" >&2
            failed=1
            continue
        fi
        if ! is_release_entry_name "$name"; then
            echo "Refusing unsafe release deletion: $path" >&2
            failed=1
            continue
        fi
        if contains_name "$name" "${protected_names[@]}" || contains_name "$name" "${retained_names[@]}"; then
            echo "Refusing unsafe release deletion: $path" >&2
            failed=1
            continue
        fi
        rm -rf -- "$canonical"
        printf 'deleted=%s\n' "$name"
    done
fi

exit "$failed"
