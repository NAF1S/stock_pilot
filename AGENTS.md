
# StockPilot — Agent Guide

StockPilot is a two-part inventory & warehouse platform for businesses that assemble and sell
kit / multi-level assembly products: a **Laravel 13 + React 19 web portal** (`portal/`) and a
**read-only WooCommerce plugin** (`stockpilot-for-woocommerce/`) that pushes orders and catalog
data to the portal over HMAC-signed webhooks.

## Repository map

| Path                                                               | What it is                                                                            |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------- |
| [portal/](portal/AGENTS.md)                                         | Laravel API + React SPA. All inventory logic lives here.                              |
| [stockpilot-for-woocommerce/](stockpilot-for-woocommerce/AGENTS.md) | WordPress plugin (read-only bridge).                                                  |
| [scripts/](scripts/AGENTS.md)                                       | Production bundle builders + Python ML / Supabase utilities.                          |
| [scratch/](scratch/)                                                | Throwaway Playwright/Node diagnostics against a live server. Not shipped, not tested. |

Deeper documentation — read before large changes:

- [README.md](README.md) — architecture, inventory mechanics, env vars, CLI, troubleshooting.
- [CONTEXT.md](CONTEXT.md) — condensed state-of-the-repo brief for onboarding an agent/LLM.
- [DEPLOYMENT.md](DEPLOYMENT.md) — shared hosting vs VPS, cron, rollout, rollback.
- [FORECASTING.md](FORECASTING.md) — ML forecasting schema, API, Python worker.

## Non-negotiables

1. **The stock ledger is append-only.** Rows in `sp_item_movements` are never updated or deleted;
   corrections are new rows pointing at the original through `reversal_of`.
2. **Every business row is tenant-scoped by `organization_id`.** Models use the
   `BelongsToOrganization` trait; cross-tenant reads/writes must stay impossible
   (see [portal/app/Models/AGENTS.md](portal/app/Models/AGENTS.md)).
3. **The WooCommerce plugin never writes to WooCommerce.** No status updates, no stock functions,
   no post-meta writes — it only reads and forwards.
4. **Never hand-edit an applied migration, `vendor/`, `node_modules/`, `portal/public/build/`,
   or `portal/storage/`.** Add a new migration instead; `AutoMigrate` applies new files to live installs.

## Commands

Everything runs from `portal/` unless noted:

```bash
composer install && npm install
npm run build                 # Vite build -> portal/public/build (needed to serve the SPA)
php artisan serve --port=8000 # or: composer dev  (serve + queue + logs + vite concurrently)
php artisan test              # Pest suite (sqlite :memory:)
npx tsc --noEmit              # type-check the SPA (`npm run build` does NOT type-check)
```

Release bundles (from the repo root):

```bash
bash scripts/build-portal-upload.sh   # -> portal-upload.zip
bash scripts/build-plugin-upload.sh   # lints PHP, -> stockpilot.zip
```

## Gotchas

- Webhooks are processed **inline** by default (`SP_WEBHOOK_MODE=sync`) so shared hosting needs no
  queue worker; `queue` mode requires `php artisan queue:work`.
- `scripts/build-portal-upload.sh` writes a production `.env` containing hardcoded credentials.
  Treat those values as sensitive: do not copy them into code, docs, commits, or new files.
