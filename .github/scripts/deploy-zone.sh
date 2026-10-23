#!/usr/bin/env bash
set -euo pipefail

fail() { printf '%s\n' "$*" >&2; exit 1; }

[[ $# == 3 ]] || fail 'Usage: deploy-zone.sh PROJECT_ROOT RELEASE_ID PHP_BINARY'
project_root=$1
release_id=$2
php_binary=$3

[[ "$project_root" == /* && "$project_root" != / && "${project_root##*/}" == van ]] || fail 'Invalid project root.'
[[ -d "$project_root" && ! -L "$project_root" && "$(realpath "$project_root")" == "$project_root" ]] || fail 'Project root must be a real directory.'
[[ -f "$project_root/.van-project" && ! -L "$project_root/.van-project" ]] || fail 'Missing project marker.'
[[ "$(< "$project_root/.van-project")" == asquare/van-project ]] || fail 'Incorrect project marker.'
[[ "$release_id" =~ ^[a-f0-9]{40}-[0-9]+-[0-9]+$ ]] || fail 'Invalid release identifier.'
[[ -f "$project_root/.env" && ! -L "$project_root/.env" ]] || fail 'Configure the server .env before deployment.'
for directory in releases .incoming storage; do
    [[ ! -L "$project_root/$directory" ]] || fail "Unexpected symlink: $directory"
done
if [[ -e "$project_root/public" || -L "$project_root/public" ]]; then
    [[ -L "$project_root/public" && "$(readlink "$project_root/public")" == current/public ]] || fail 'public must be absent or point to current/public.'
fi
if [[ -e "$project_root/current" || -L "$project_root/current" ]]; then
    [[ -L "$project_root/current" && "$(readlink "$project_root/current")" == releases/* ]] || fail 'current must point to a project release.'
    previous_release=$(realpath "$project_root/current")
    [[ "$previous_release" == "$project_root/releases/"* ]] || fail 'Current release escapes the project directory.'
fi
command -v flock > /dev/null || fail 'The server needs flock.'
command -v "$php_binary" > /dev/null || fail 'The requested PHP CLI is unavailable.'
"$php_binary" -r 'exit(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500 ? 0 : 1);' || fail 'This deployment uses PHP 8.4 CLI.'

exec 9> "$project_root/.deploy.lock"
flock -n 9 || fail 'Another deployment holds the project lock.'
umask 022
chmod 600 "$project_root/.env"

archive="$project_root/.incoming/$release_id.tar.gz"
release="$project_root/releases/$release_id"
[[ -f "$archive" && ! -L "$archive" ]] || fail 'Release archive is missing.'
[[ ! -e "$release" && ! -L "$release" ]] || fail 'This release already exists; use a new workflow attempt.'
mkdir -p "$project_root/releases" "$project_root/storage"
mkdir "$release"
tar -xzf "$archive" -C "$release"
chmod 755 "$release"
[[ -f "$release/artisan" && -f "$release/vendor/autoload.php" && -f "$release/public/build/manifest.json" ]] || fail 'Incomplete release archive.'
[[ ! -e "$release/.env" && ! -L "$release/.env" ]] || fail 'Release archives must not contain .env.'
[[ -d "$release/storage" && ! -L "$release/storage" ]] || fail 'Invalid storage template.'
mv "$release/storage" "$release/.storage-template"
mkdir -p "$project_root/storage/app/private" "$project_root/storage/app/public" \
    "$project_root/storage/framework/cache/data" "$project_root/storage/framework/cache/locks" \
    "$project_root/storage/framework/sessions" "$project_root/storage/framework/views" "$project_root/storage/logs"
ln -s "$project_root/storage" "$release/storage"
ln -s "$project_root/.env" "$release/.env"
cd "$release"

"$php_binary" <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = [
    'APP_ENV must be production' => $app->environment('production'),
    'APP_DEBUG must be false' => config('app.debug') === false,
    'APP_URL must be https://van.asquare.ee' => config('app.url') === 'https://van.asquare.ee',
    'APP_KEY must be configured' => is_string(config('app.key')) && str_starts_with(config('app.key'), 'base64:') && strlen((string) base64_decode(substr(config('app.key'), 7), true)) === 32,
    'MySQL must be configured' => config('database.default') === 'mysql' && filled(config('database.connections.mysql.host')) && filled(config('database.connections.mysql.database')) && filled(config('database.connections.mysql.username')) && filled(config('database.connections.mysql.password')),
    'Database sessions and encryption are required' => config('session.driver') === 'database' && config('session.encrypt') === true,
    'SMTP must be configured' => config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')),
];
foreach ($checks as $message => $valid) {
    if (! $valid) {
        file_put_contents('php://stderr', $message.PHP_EOL);
        exit(1);
    }
}
PHP

"$php_binary" artisan config:cache
maintenance_started=0
finish() {
    result=$?
    if [[ "$maintenance_started" == 1 ]]; then
        "$php_binary" artisan up || true
    fi
    if [[ "$result" != 0 ]]; then
        printf '%s\n' 'Deployment failed. The database has not been rolled back. Inspect the failing step before retrying.' >&2
    fi
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
maintenance_started=1
"$php_binary" artisan down --retry=60
"$php_binary" artisan migrate --force
"$php_binary" artisan route:cache
"$php_binary" artisan view:cache

if [[ ! -L "$project_root/public" ]]; then
    ln -s current/public "$project_root/public"
fi
ln -s "releases/$release_id" "$project_root/.current-$release_id"
mv -Tf "$project_root/.current-$release_id" "$project_root/current"
"$php_binary" artisan up
maintenance_started=0
printf 'Published release %s\n' "$release_id"
