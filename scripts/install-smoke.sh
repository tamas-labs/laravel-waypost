#!/bin/sh
# Installs the package into a fresh Laravel 13 application, as a host would: composer create-project
# laravel/laravel, path repositories for laravel-waypost and waypost-schema, composer require, waypost:install,
# waypost:create-supervisor. The application uses its default SQLite database. Needs network access (Packagist).
# Run it in the php service: docker compose run --rm php sh scripts/install-smoke.sh
set -eu

package_dir=$(cd "$(dirname "$0")/.." && pwd)
schema_dir=$(cd "$package_dir/../waypost-schema" && pwd)
work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT
app="$work_dir/app"

step() { printf '\n==> %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

step "composer create-project laravel/laravel ^13.0"
composer create-project --no-interaction --quiet laravel/laravel "$app" '^13.0'
cd "$app"

step "composer require waypost/laravel-waypost (path repositories)"
composer config repositories.waypost-schema "{\"type\": \"path\", \"url\": \"$schema_dir\", \"options\": {\"versions\": {\"waypost/schema\": \"0.1.0\"}}}"
composer config repositories.laravel-waypost "{\"type\": \"path\", \"url\": \"$package_dir\", \"options\": {\"versions\": {\"waypost/laravel-waypost\": \"0.1.0\"}}}"
composer require --no-interaction --quiet waypost/laravel-waypost:0.1.0

step "php artisan waypost:install --migrate"
php artisan waypost:install --migrate --no-interaction
[ -f config/waypost.php ] || fail "config/waypost.php was not published"
published=$(ls database/migrations | grep -c '_create_waypost_') || true
expected=$(ls "$package_dir/database/migrations" | grep -c '\.php$')
[ "$published" -eq "$expected" ] || fail "published $published migrations, expected $expected"
php artisan migrate:status --no-interaction | grep -q 'create_waypost_users_table .* Ran' || fail "the migrations did not run"

step "php artisan waypost:install again: nothing new"
php artisan waypost:install --migrate --no-interaction | grep -q 'A migrációk már publikálva vannak.' || fail "the second run published migrations"
[ "$(ls database/migrations | grep -c '_create_waypost_')" -eq "$expected" ] || fail "the second run duplicated migrations"

step "php artisan waypost:create-supervisor"
php artisan waypost:create-supervisor --name='Smoke Supervisor' --email=smoke@example.com --password=smoke-password --no-interaction
if php artisan waypost:create-supervisor --name='Again' --email=smoke@example.com --password=smoke-password --no-interaction; then
    fail "the same e-mail address was accepted twice"
fi
count=$(php -r 'echo (new PDO("sqlite:database/database.sqlite"))->query("select count(*) from waypost_users where role = \x27supervisor\x27")->fetchColumn();')
[ "$count" = "1" ] || fail "waypost_users has $count rows, expected 1"

printf '\nok: waypost/laravel-waypost installed into laravel/laravel %s, %s migrations, 1 supervisor\n' \
    "$(php artisan --version | sed 's/Laravel Framework //')" "$expected"
