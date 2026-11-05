#!/bin/sh
# Installs the package into a fresh Laravel 13 application, as a host would: composer create-project
# laravel/laravel, path repositories for laravel-waypost and waypost-schema, composer require, waypost:install,
# waypost:create-supervisor, then a login through its HTTP kernel and a photo upload over real HTTP. The application
# uses its default SQLite database. Needs network access (Packagist).
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
composer config repositories.waypost-schema "{\"type\": \"path\", \"url\": \"$schema_dir\", \"options\": {\"versions\": {\"waypost/schema\": \"0.5.0\"}}}"
composer config repositories.laravel-waypost "{\"type\": \"path\", \"url\": \"$package_dir\", \"options\": {\"versions\": {\"waypost/laravel-waypost\": \"0.1.0\"}}}"
composer require --no-interaction --quiet waypost/laravel-waypost:0.1.0

step "php artisan waypost:install --migrate"
php artisan waypost:install --migrate --no-interaction
[ -f config/waypost.php ] || fail "config/waypost.php was not published"
published=$(ls database/migrations | grep -c '_waypost_') || true
expected=$(ls "$package_dir/database/migrations" | grep -c '\.php$')
[ "$published" -eq "$expected" ] || fail "published $published migrations, expected $expected"
php artisan migrate:status --no-interaction | grep -q 'create_waypost_users_table .* Ran' || fail "the migrations did not run"

step "php artisan waypost:install again: nothing new"
php artisan waypost:install --migrate --no-interaction | grep -q 'A migrációk már publikálva vannak.' || fail "the second run published migrations"
[ "$(ls database/migrations | grep -c '_waypost_')" -eq "$expected" ] || fail "the second run duplicated migrations"

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

step "PUT /api/waypost/v1/media/{id} over real HTTP: PHP parses a multipart PUT only with request_parse_body()"
token=$(echo "$response" | sed -E 's/.*"token":"([^"]*)".*/\1/')
php artisan serve --no-interaction --port=8765 > "$work_dir/serve.log" 2>&1 &
serve_pid=$!
trap 'kill "$serve_pid" 2>/dev/null || true; rm -rf "$work_dir"' EXIT
sleep 2
cat > "$work_dir/upload.php" <<'PHP'
<?php
// Usage: php upload.php <token>. Uploads a photo of a new checkpoint, prints the photo's ID and the response's status and body.
$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00smoke\xFF\xD9";
$id = '019a2b3c-4d5e-7f00-8a00-000000000b01';
$fields = ['sha256' => hash('sha256', $jpeg), 'owner_type' => 'checkpoint', 'owner_id' => '019a2b3c-4d5e-7f00-8a00-000000000b02', 'taken_at' => '2026-10-27T20:04:05.123Z'];
$boundary = 'smoke-boundary';
$body = '';
foreach ($fields as $name => $value) {
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
}
$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"photo.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n{$jpeg}\r\n--{$boundary}--\r\n";
$headers = ["Authorization: Bearer {$argv[1]}", 'Accept: application/json', "Content-Type: multipart/form-data; boundary={$boundary}", 'Idempotency-Key: 019a2b3c-4d5e-7f00-8a00-000000000b03'];
$content = file_get_contents("http://127.0.0.1:8765/api/waypost/v1/media/{$id}", false, stream_context_create(['http' => ['method' => 'PUT', 'header' => $headers, 'content' => $body, 'ignore_errors' => true]]));
preg_match('/ (\d{3}) /', $http_response_header[0], $status);
echo $id, ' ', $status[1], ' ', $content, "\n";
PHP
response=$(php "$work_dir/upload.php" "$token")
media_id=${response%% *}
echo "$response" | grep -q ' 200 {"server_time":"[^"]*","media":{"id":"' || fail "the multipart PUT did not store the photo: $response ($(cat "$work_dir/serve.log"))"
[ -f "storage/app/private/waypost/media/$media_id.jpg" ] || fail "the photo is not on the private disk"
kill "$serve_pid"

step "Reports: the package's views compile in the host, the reports, the alerts and the retention are scheduled"
php artisan view:cache --no-interaction > /dev/null || fail "the package's views do not compile"
schedule=$(php artisan schedule:list --no-interaction)
for job in waypost:send-due-reports waypost:send-no-data-alerts waypost:prune-expired-data; do
    printf '%s' "$schedule" | grep -q "$job" || fail "$job is not scheduled"
done

printf '\nok: waypost/laravel-waypost installed into laravel/laravel %s, %s migrations, 1 supervisor, login and upload work\n' \
    "$(php artisan --version | sed 's/Laravel Framework //')" "$expected"
