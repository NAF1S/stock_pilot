# StockPilot Web Portal

Laravel 13 + React (TypeScript, single Vite build). Self-installing inventory & warehouse
management portal.

## Requirements

- PHP 8.3+ (`pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `intl` extensions)
- Composer 2
- Node 20+ (for building the frontend only)
- MySQL 8 / MariaDB 10.11+

## Local development

```bash
composer install
npm install

# Build the SPA once, or run `npm run dev` for hot reload during development.
npm run build

php artisan serve --port=8000
```

The default `.env` uses SQLite so you can start instantly. For the real flow, use the setup
wizard on first load, or edit `.env` to MySQL:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stockpilot
DB_USERNAME=root
DB_PASSWORD=
```

If the tables are missing, the **first web request auto-runs the migrations**.

## Background workers (production)

The portal relies on the queue + scheduler. Add to your server cron:

```
* * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` schedules:

- `snapshots:capture` — nightly asset-value snapshot (time-series reports).
- `warnings:evaluate` — hourly low-stock sweep.
- `queue:work --once --stop-when-empty` — every minute, drains the DB queue even if no
  dedicated worker runs.

For higher order volume, run a dedicated worker instead and drop that line:
`php artisan queue:work database --sleep=3`.

## Useful commands

| Command | Purpose |
|---|---|
| `php artisan orders:simulate tests/fixtures/orders.json` | Feed fixture order events through the engine to test reserve/confirm/release/return |
| `php artisan snapshots:capture` | Capture an asset snapshot now |
| `php artisan warnings:evaluate` | Re-evaluate low-stock warnings now |
| `php artisan migrate:fresh` | Reset the dev database |
| `php artisan test` | Run the Pest test suite (inventory engine is covered) |

## Deploy checklist

- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, generate a fresh `APP_KEY`.
- [ ] HTTPS enforced (HSTS); `APP_URL` matches the public domain.
- [ ] `.env`: `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`, `MAIL_MAILER=sendmail` (or SMTP).
- [ ] `php artisan config:cache && php artisan route:cache` after each deploy.
- [ ] Server cron for the scheduler (above).
- [ ] Offsite database backups.
- [ ] `SANCTUM_STATEFUL_DOMAINS` set to your portal domain.
- [ ] Keep the `public/build` (Vite output) up to date after frontend changes.

## Testing

`php artisan test` runs the Pest suite. The most important coverage is
`tests/Feature/InventoryEngineTest.php`: reserve, confirm, release, return-with-charge,
duplicate events, out-of-order completion, partial reserve, ledger reversal integrity,
asset value, and low-stock emails.

## Project layout (key pieces)

- `app/Services/InventoryEngine.php` — reserve/confirm/release/return state machine + ledger writes.
- `app/Jobs/ProcessWebhookEvent.php` — queue job that runs the engine after ingest.
- `app/Services/ProductSyncProcessor.php` — Woo product catalog metadata sync.
- `app/Http/Middleware/AuthenticateStoreKey.php` — HMAC auth for the plugin.
- `resources/js/` — the React SPA (dashboard, items, products/recipes, orders, reports, stores, settings, users, setup).
