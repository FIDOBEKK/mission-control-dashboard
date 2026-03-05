#!/usr/bin/env sh
set -e

if [ ! -f .env ]; then
  cp .env.example .env
fi

php artisan key:generate --force >/dev/null 2>&1 || true
mkdir -p database
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --force >/dev/null 2>&1 || true

exec "$@"
