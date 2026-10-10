# portal/database — Migrations, factories, seeders

Thirteen migrations create the schema. `sp_` prefixed tables are StockPilot domain tables; the framework
tables (`users`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`,
`password_reset_tokens`, `personal_access_tokens`) keep Laravel's default names — note that
`sp_users` is the application user table, **not** `users`.

## Conventions

- Anonymous-class migrations with `up()` / `down()`; `down()` must reverse `up()`.
- Column conventions: `$table->id()`; `foreignId('x_id')->constrained('sp_...')`; `decimal(..., 14, 4)` for
  every quantity and money value; `enum(...)` for closed state sets (`reservation_state`, `movement_type`,
  `risk_level`, `urgency`, `status`); named composite indexes/constraints where a name matters, e.g.
  `sp_forecasts_lookup_idx`, `sp_products_org_active_idx`.
- Composite uniques encode business identity — don't drop them:
  `unique(['organization_id', 'sku'])`, `unique(['store_id', 'woocommerce_id'])`, `unique(['product_id','item_id'])`
  on BOM lines, and the webhook dedup pair `unique(['store_id','event_id'])` + `unique(['store_id','nonce'])`.
- `sp_settings` uses a composite primary key `(organization_id, key)`.
- `sp_item_movements` is append-only: `created_at` uses `useCurrent()`, there is **no** `updated_at`, and
  corrections are new rows with `reversal_of`.

## Adding a schema change

1. Never edit an applied migration — add a new timestamped file.
2. Guard ALTER migrations with `Schema::hasColumn()` / `Schema::hasIndex()`: MySQL DDL auto-commits, and a
   half-applied migration must be safely re-runnable. `2026_08_05_000001_add_multi_level_bom_and_product_reservations.php`
   is the reference implementation.
3. Update the model (`#[Fillable]`, casts, relations) — see [../app/Models/AGENTS.md](../app/Models/AGENTS.md).
4. Remember `AutoMigrate` applies new files automatically on existing installs, so a missing guard or a
   destructive change reaches production unattended.

## Noteworthy migrations

| Migration | Why it matters |
|---|---|
| `2026_08_01_000004_create_item_movements_table` | The append-only stock ledger (`qty_delta`, `reserved_delta`, `on_hand_after`, `reversal_of`) |
| `2026_08_01_000005_create_order_tables` | Orders, line items, component snapshots (`sp_order_item_components`), status events |
| `2026_08_05_000001_add_multi_level_bom_and_product_reservations` | Makes `item_id` nullable and adds `product_id` / `component_product_id` / `component_type` so products can be components of other products |
| `2026_10_10_000001_create_ml_forecasting_tables` | `sp_demand_forecasts`, `sp_stockout_risks`, `sp_restock_recommendations` — also written by [scripts/ml_forecasting_worker.py](../../scripts/ml_forecasting_worker.py) |

## Factories & seeders

Only `UserFactory` exists. `DatabaseSeeder` creates `test@example.com` and is otherwise empty. Tests do
**not** use factories for domain data — they build fixtures by hand in the test file (see
[../tests/AGENTS.md](../tests/AGENTS.md)).

Tests run on SQLite `:memory:` (`phpunit.xml`), so avoid MySQL-only SQL on code paths covered by tests.
