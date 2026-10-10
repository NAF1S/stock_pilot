# portal/ — StockPilot Web Portal

Laravel 13 API + React 19 SPA built as a single Vite bundle. This is where all inventory,
order, reporting and forecasting logic lives. The root [AGENTS.md](../AGENTS.md) covers the
whole repo; [README.md](README.md) is the portal-level quickstart.

## Requirements & commands

PHP 8.3+ (`pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `curl`, `fileinfo`, `intl`, `bcmath`),
Composer 2, Node 20+, MySQL 8 / MariaDB 10.11+ (SQLite is fine for local dev and required for tests).

```bash
composer install
npm install
npm run build              # must be run before serving: the SPA loads from public/build
php artisan serve --port=8000
composer dev               # serve + queue:listen + pail + vite, concurrently
php artisan test           # Pest suite (sqlite :memory:, see phpunit.xml)
npx tsc --noEmit           # SPA type-check; `npm run build` does not run tsc
php artisan migrate:fresh  # local database reset
```

## How a request flows

- `routes/web.php` is a catch-all that renders `resources/views/app.blade.php` (the SPA shell with
  `@vite([...])`). It 404s `api/*` and `sanctum/*` so those never return HTML.
- `routes/api.php` → `app/Http/Controllers/Api/*` → `app/Services/*` → `app/Models/*`.
- Browser auth is **Sanctum cookie sessions** (`$middleware->statefulApi()` in `bootstrap/app.php`),
  not API tokens. The SPA hits `/sanctum/csrf-cookie` before login
  (`resources/js/bootstrap.ts` → `lib/auth.tsx`). Its `ensureFrontendRequestsAreStateful` middleware
  injects the session stack for stateful requests — **do not also add `StartSession`/`EncryptCookies`
  to the `api` group**, a second set clobbers the session and every authenticated call returns 401.

## Self-install behaviour (deliberate, easy to break)

- `app/Http/Middleware/AutoMigrate` is **prepended** to both the `web` and `api` groups so a fresh
  database is migrated before the session table is first read. On an already-installed database it
  applies newly shipped migration files.
- `AppServiceProvider::ensureAppKey()` generates and writes `APP_KEY` when it is missing/invalid.
- `SetupController` + `SetupService` drive the first-run wizard and can rewrite the `DB_*` block in
  `.env` and test a MySQL connection.

## Background work

`routes/console.php` schedules:

| Command | When |
|---|---|
| `snapshots:capture` | daily 00:05 — asset-value snapshot |
| `warnings:evaluate` | hourly — low-stock sweep |
| `queue:work --once --stop-when-empty` | every minute — drains the DB queue without a supervisor |

The server needs `* * * * * php artisan schedule:run`. Hosts with restricted cron can call
[cron/stock-summary.php](cron/stock-summary.php) directly instead.

## Webhook ingestion

`config('stockpilot.webhook_mode')` (`SP_WEBHOOK_MODE`) decides how `WebhookController` runs events:
`sync` (default — inline, rolls the event row back on failure so the plugin retries) or `queue`
(store then `ProcessWebhookEvent` on the queue).

## Layout

| Path | Contents |
|---|---|
| [app/](app/AGENTS.md) | Backend: Http, Services, Models, Jobs, Console, Mail, Providers |
| [routes/](routes/AGENTS.md) | API / web / console route map and middleware usage |
| [database/](database/AGENTS.md) | Migrations, factories, seeders |
| [config/](config/AGENTS.md) | Configuration, including the custom `stockpilot.php` |
| [resources/js/](resources/js/AGENTS.md) | React SPA |
| [tests/](tests/AGENTS.md) | Pest test suite and fixtures |
| `resources/views/` | `app.blade.php` SPA shell, mail templates |
| `cron/stock-summary.php` | Standalone daily digest entry point |
| `bootstrap/` | Application + middleware wiring, config cache (generated) |

## Configuration & environment

`.env.example` ships Supabase PostgreSQL as the default `DB_CONNECTION`; MySQL/SQLite are the
documented alternatives. The variables that change behaviour are `APP_KEY`, `APP_URL`,
`DB_*`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SANCTUM_STATEFUL_DOMAINS`,
`QUEUE_CONNECTION`, `CACHE_STORE`, `MAIL_MAILER` and `SP_WEBHOOK_MODE` — the full table is in the
root [README.md](../README.md#configuration--environment-variables).
