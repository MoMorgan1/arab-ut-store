#!/usr/bin/env bash

set -Eeuo pipefail

deploy_root="${1:?Deploy root is required}"
mode="${2:?Maintenance mode is required}"
health_url="${3:?Health URL is required}"

if [[ "$mode" != "audit" && "$mode" != "apply" ]]; then
    echo 'Maintenance mode must be audit or apply.' >&2
    exit 1
fi

if [[ "$deploy_root" != /* || "$deploy_root" == "/" ]]; then
    echo 'Deploy root must be an absolute non-root path.' >&2
    exit 1
fi

current="$deploy_root/current"
releases="$deploy_root/releases"
incoming="$deploy_root/incoming"
shared="$deploy_root/shared"

for required_path in "$current" "$releases" "$shared/.env" "$shared/storage"; do
    if [[ ! -e "$required_path" ]]; then
        echo "Required deployment path is missing: $required_path" >&2
        exit 1
    fi
done

current_release="$(readlink -f "$current")"
if [[ "$current_release" != "$releases/"* || ! -d "$current_release" ]]; then
    echo 'The current release does not resolve inside the releases directory.' >&2
    exit 1
fi

count_matches() {
    if [[ ! -d "$1" ]]; then
        echo 0
        return
    fi

    find "$1" -mindepth 1 -maxdepth 1 "${@:2}" -printf '.' 2>/dev/null | wc -c
}

print_inventory() {
    echo "mode=$mode"
    echo "current_release=$(basename "$current_release")"
    echo "release_count=$(count_matches "$releases" -type d)"
    echo "incoming_archives=$(count_matches "$incoming" -type f -name '*.tar.gz')"
    echo "temporary_links=$(count_matches "$deploy_root" -type l \( -name '.current-*' -o -name '.rollback-*' \))"
    echo "legacy_public_backups=$(count_matches "$deploy_root" -type d -name 'public_html.before-laravel-*')"
    echo "compiled_views=$(find "$shared/storage/framework/views" -maxdepth 1 -type f ! -name '.gitignore' -printf '.' 2>/dev/null | wc -c)"
    echo "logs_older_than_30_days=$(find "$shared/storage/logs" -maxdepth 1 -type f -mtime +30 -printf '.' 2>/dev/null | wc -c)"
    du -sh "$deploy_root" "$shared/storage" 2>/dev/null || true
}

print_inventory

if [[ "$mode" == "audit" ]]; then
    exit 0
fi

if ! curl --fail --silent --show-error --max-time 10 "$health_url/up" >/dev/null; then
    echo 'Production health check failed; cleanup was cancelled.' >&2
    exit 1
fi

if [[ -d "$incoming" ]]; then
    find "$incoming" -mindepth 1 -maxdepth 1 -type f -name '*.tar.gz' -mtime +1 -delete
fi
find "$deploy_root" -mindepth 1 -maxdepth 1 -type l \( -name '.current-*' -o -name '.rollback-*' \) -delete
find "$deploy_root" -mindepth 1 -maxdepth 1 -type d -name 'public_html.before-laravel-*' -exec rm -rf -- {} +
find "$shared/storage/logs" -mindepth 1 -maxdepth 1 -type f -mtime +30 -delete

cd "$current"
php artisan view:clear
php artisan view:cache

echo 'cleanup_complete=true'
print_inventory
