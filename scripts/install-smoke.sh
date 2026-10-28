#!/bin/sh
# Installs the package into a fresh Laravel 13 application, as a host would: composer create-project
# laravel/laravel, path repositories for laravel-waypost and waypost-schema, composer require, waypost:install,
# waypost:create-supervisor, then a login through its HTTP kernel. The application uses its default SQLite
# database. Needs network access (Packagist).
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

step "POST /api/waypost/v1/auth/login through the application's HTTP kernel"
cat > "$work_dir/login.php" <<'PHP'
<?php
// Usage: php login.php <password>. Prints the status and the body of the response.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$body = json_encode(['email' => 'Smoke@Example.com', 'password' => $argv[1], 'device_name' => 'smoke']);
$request = Illuminate\Http\Request::create('/api/waypost/v1/auth/login', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body);
$response = $kernel->handle($request);
echo $response->getStatusCode(), ' ', $response->getContent(), "\n";
PHP
response=$(php "$work_dir/login.php" wrong)
echo "$response" | grep -q '^401 {"server_time":"[^"]*","error":{"code":"unauthenticated"' || fail "a wrong password did not get the API's error format: $response"
response=$(php "$work_dir/login.php" smoke-password)
echo "$response" | grep -q '^200 {"server_time":"[^"]*","token":"' || fail "the login did not issue a token: $response"
php artisan route:list --path=api/waypost/v1 --no-interaction | grep -q 'waypost.auth.logout' || fail "the API routes are not registered"

printf '\nok: waypost/laravel-waypost installed into laravel/laravel %s, %s migrations, 1 supervisor, login works\n' \
    "$(php artisan --version | sed 's/Laravel Framework //')" "$expected"
