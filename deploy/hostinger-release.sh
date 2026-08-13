#!/usr/bin/env bash

set -Eeuo pipefail

deploy_root="${1:?Deploy root is required}"
archive_path="${2:?Release archive is required}"
release_id="${3:?Release identifier is required}"
health_url="${4:?Health URL is required}"

releases="$deploy_root/releases"
shared="$deploy_root/shared"
current="$deploy_root/current"
public_html="$deploy_root/public_html"
release="$releases/$release_id"
env_file="$shared/.env"

if [[ ! -f "$env_file" ]]; then
    echo 'The shared/.env file is missing.' >&2
    exit 1
fi

if [[ ! -f "$archive_path" ]]; then
    echo 'The verified release archive is missing.' >&2
    exit 1
fi

mkdir -p \
    "$releases" \
    "$shared/storage/app/public" \
    "$shared/storage/framework/cache/data" \
    "$shared/storage/framework/sessions" \
    "$shared/storage/framework/views" \
    "$shared/storage/logs"

rm -rf "$release"
mkdir -p "$release"
tar -xzf "$archive_path" -C "$release"

ln -s "$env_file" "$release/.env"
rm -rf "$release/storage"
ln -s "$shared/storage" "$release/storage"

cd "$release"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
php artisan migrate --force
rm -rf "$release/public/storage"
ln -sfn "$shared/storage/app/public" "$release/public/storage"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan currency:refresh-display-rates

previous_release="$(readlink -f "$current" 2>/dev/null || true)"
next_link="$deploy_root/.current-$release_id"

ln -s "$release" "$next_link"
mv -Tf "$next_link" "$current"

if [[ ! -L "$public_html" ]]; then
    if [[ -d "$public_html" ]]; then
        mv "$public_html" "$deploy_root/public_html.before-laravel-$(date -u +%Y%m%d%H%M%S)"
    elif [[ -e "$public_html" ]]; then
        rm -f "$public_html"
    fi
fi

ln -sfn "$current/public" "$public_html"

health_passed=false
for attempt in 1 2 3 4 5 6; do
    if curl --fail --silent --show-error --max-time 10 "$health_url/up" >/dev/null; then
        health_passed=true
        break
    fi

    sleep 5
done

if [[ "$health_passed" != true ]]; then
    if [[ -n "$previous_release" && -d "$previous_release" ]]; then
        rollback_link="$deploy_root/.rollback-$release_id"
        ln -s "$previous_release" "$rollback_link"
        mv -Tf "$rollback_link" "$current"
        ln -sfn "$current/public" "$public_html"
    fi

    echo 'The release failed its health check and the prior release was restored.' >&2
    exit 1
fi

rm -f "$archive_path"

find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
    | sort -nr \
    | tail -n +6 \
    | cut -d' ' -f2- \
    | xargs -r rm -rf

echo "Activated release $release_id"
