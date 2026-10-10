# scripts — build & operational utilities

## Bundle builders

| Script | Output | Steps |
|---|---|---|
| [build-portal-upload.sh](build-portal-upload.sh) + [build-portal-upload.cmd](build-portal-upload.cmd) | `portal-upload.zip` | (1) `npm run build` unless `--skip-build` (2) stage app source into a temp dir (3) create a fresh `storage/` skeleton (4) `composer install --no-dev`, drop the SQLite file, logs and framework/bootstrap caches (5) write a production `.env` and zip `portal/` at the archive root |
| [build-plugin-upload.sh](build-plugin-upload.sh) + [build-plugin-upload.cmd](build-plugin-upload.cmd) | `stockpilot.zip` | `php -l` every plugin file (abort on syntax error), then zip `stockpilot-for-woocommerce/` at the archive root — the layout WordPress's "Upload Plugin" expects |

- The `.cmd` files are double-click wrappers that invoke the `.sh` through Git Bash (and print a hint when
  Bash is missing). Keep the two variants in sync when you change a build step.
- The portal builder auto-detects `php`/`composer`/`npm` (overridable with `PHP_BIN`, `COMPOSER_BIN`,
  `NPM_BIN`) and skips the frontend build when `--skip-build` is passed, warning if `public/build/manifest.json`
  is absent.
- **`build-portal-upload.sh` bakes a production `.env` into the archive**, including DB credentials and an
  `APP_KEY`. Treat those values as sensitive: never copy them into code, docs, commits, or new files, and
  prefer replacing them with placeholders when you touch the script.
- Both `*_upload.zip` artifacts are git-ignored (`*.zip`). Don't commit them.

## Python utilities

- [ml_forecasting_worker.py](ml_forecasting_worker.py) — standalone forecasting worker.
  `--engine prophet|velocity`, `--periods <n>`, `--dry-run`. Reads consumption deltas from
  `sp_item_movements` and writes `sp_demand_forecasts`, `sp_stockout_risks` and
  `sp_restock_recommendations`. Needs `psycopg2-binary` (`+ pandas`, `prophet` for the ML engine); connects
  using `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_SSLMODE`. The portable fallback
  that needs no Python is `php artisan ml:forecast` in the portal. See
  [../FORECASTING.md](../FORECASTING.md).
- [migrate_to_supabase.py](migrate_to_supabase.py) — one-off MySQL-dump → Supabase PostgreSQL importer; it
  shells out to `psql` and rewrites column/type differences between the two engines. Same secret rule as
  above: it carries hardcoded connection details that should be externalized, not duplicated.
- [../scratch/supabase_import.sql](../scratch/supabase_import.sql) and the rest of `scratch/` are throwaway
  diagnostics (Playwright/Node) — not part of the build. Don't add new dependencies or features there.

## Rules

- PowerShell is not used here: the `.sh` scripts are bash with `set -euo pipefail`, so test them through
  Git Bash on Windows.
- Never commit generated output: `portal-upload.zip`, `stockpilot.zip`, `node_modules/`,
  `portal/public/build/`.
