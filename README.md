# Mission Control Dashboard (Laravel)

Laravel-first migration of the Mission Control prototype.

## Stack

- Laravel 12
- Blade + Tailwind (Vite)
- Vanilla JS auto-refresh every 20s

## Routes

- `GET /` Mission Control dashboard UI
- `GET /api/mission` live mission JSON payload

## Live data sources

The API collects data with `Symfony\Component\Process\Process` and graceful fallback per source:

- `openclaw cron list --json`
- `gh issue list --repo OnePagerHub/frame-generator`
- `gh pr list --repo OnePagerHub/frame-generator`
- `ps -Ao pid,pcpu,pmem,comm,args`
- `git log` from detected local repos

Each source contributes diagnostics in `sources[]` with `ok`/`message`.

## Run locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan serve
npm run dev
```

App URL: `http://127.0.0.1:8000`

## Build + test

```bash
npm run build
php artisan test --compact
```
