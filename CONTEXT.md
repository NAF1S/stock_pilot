# StockPilot — Project Context

**Audience:** an LLM/engineer starting work on this repo — in particular **building the separate
Python ML server**. This file is the "state of the world" summary: what exists, how it works, what is
missing, and what any new component must respect.

**How to use it:** read this file first for the whole picture, then open the nested `AGENTS.md` files
for working rules per folder (see [Doc index](#doc-index)). Facts below were read from source at
commit `fcb504f` (2026-10-12) unless marked otherwise.

---

## 1. What StockPilot is

An inventory & warehouse management platform for businesses that **assemble and sell kit products and
multi-level assemblies** — e.g. a "Belt Kit" order consumes a leather strap, buckle, warranty card,
thank-you card, gift box and a courier mailer. The hard problem it solves: knowing the *true* stock of
both **raw components (items)** and **sellable goods (products)** when products consume each other
recursively, and keeping that correct across the full WooCommerce order lifecycle.

Two deployable parts:

| Part | Tech | Role |
|---|---|---|
| **Web portal** (`portal/`) | Laravel 13 + React 19 | All inventory logic, API, UI, ML endpoints |
| **WordPress plugin** (`stockpilot-for-woocommerce/`) | PHP 7.4+ | **Read-only** bridge: pushes orders + catalog to the portal |

The plugin **never writes to WooCommerce** (no status updates, no stock functions, no post-meta). That
is a product guarantee, not a preference.

---

## 2. Repository map

```
stock_pilot/
├── AGENTS.md                      ← repo-wide agent rules (non-negotiables, commands)
├── CONTEXT.md                     ← THIS FILE
├── README.md                      ← product/architecture deep-dive (34 KB, authoritative)
├── DEPLOYMENT.md                  ← shared hosting + VPS runbooks (24 KB)
├── FORECASTING.md                 ← ML subsystem doc (11 KB) — see §10
├── portal/                        ← Laravel 13 API + React 19 SPA
│   ├── AGENTS.md
│   ├── app/
│   │   ├── AGENTS.md
│   │   ├── Http/AGENTS.md         ← controllers + middleware contracts
│   │   ├── Models/AGENTS.md       ← schema, tenancy, conventions
│   │   ├── Services/AGENTS.md     ← InventoryEngine, BomExpander, … (domain rules)
│   │   ├── Console/Commands/      ← scheduled + operational commands
│   │   ├── Jobs/ProcessWebhookEvent.php
│   │   ├── Mail/                  ← LowStockAlert, StockSummary
│   │   └── Providers/AppServiceProvider.php
│   ├── bootstrap/app.php          ← routing + middleware wiring (read this)
│   ├── config/AGENTS.md
│   ├── database/AGENTS.md         ← 14 migrations
│   ├── resources/js/AGENTS.md     ← React SPA (+ React/AGENTS guidance)
│   ├── routes/AGENTS.md
│   ├── tests/AGENTS.md            ← Pest suite
│   └── cron/stock-summary.php     ← standalone digest entry point
├── scripts/AGENTS.md              ← bundle builders + Python utilities
│   ├── build-portal-upload.sh     ← → portal-upload.zip
│   ├── build-plugin-upload.sh     ← → stockpilot.zip
│   ├── ml_forecasting_worker.py   ← EXISTING ML worker (§10.3)
│   └── migrate_to_supabase.py     ← one-off MySQL → Supabase import
├── stockpilot-for-woocommerce/AGENTS.md
└── scratch/                       ← throwaway Playwright/Node diagnostics. Not shipped, not tested.
```

---

## 3. Runtime architecture

```
WooCommerce store (WordPress + SPW plugin v1.0.2)
   │  reads orders/products, enqueues to a local MySQL outbound queue
   │  HTTPS POST, HMAC-SHA256 signed
   ▼
POST /api/webhooks/orders   POST /api/webhooks/products     ← middleware: store.key
   │
   ▼
WebhookController  →  sp_webhook_events (dedup by store_id+event_id / store_id+nonce)
   │                  SP_WEBHOOK_MODE=sync → inline  |  =queue → ProcessWebhookEvent job
   ▼
ProcessWebhookEvent (DB transaction, locks the event + store row)
   ├── order.sync   → InventoryEngine::processOrderEvent()  → reserve/confirm/release/return
   └── product.*    → ProductSyncProcessor::syncProduct()
   ▼
InventoryEngine  →  sp_item_movements  (append-only ledger)  +  sp_order_item_components
   │
   ├── LowStockNotifier  → sp_stock_warnings + LowStockAlert email (sent synchronously)
   └── (scheduled) snapshots:capture / warnings:evaluate / stock:summary
   ▼
React SPA  ── Sanctum cookie session ──▶  /api/*   ▶  ForecastController (/api/forecasts/*)
```

The database is PostgreSQL in production (Supabase) and SQLite `:memory:` for tests. MySQL is also
supported. **The ML code must therefore avoid MySQL-only SQL** if it is to share a code path with tests.

---

## 4. Tech stack (from `composer.json` / `package.json` / plugin header)

| Layer | Versions |
|---|---|
| PHP | `^8.3` (plugin targets 7.4+) |
| Laravel | `^13.8`, Sanctum `^4.3` |
| Tests | Pest `^4.7` (+ laravel plugin `^4.1`), PHPUnit `^12.5`, Mockery, Faker |
| Frontend | React `^19.2`, TypeScript `^7.0`, Vite `^8.0`, Tailwind `^4.0`, TanStack Query `^5.101`, axios `^1.19`, React Router `^7.18` |
| Database | PostgreSQL (Supabase) prod · MySQL 8 / MariaDB 10.11 alt · SQLite for tests |
| WordPress | WP 6.0+, WooCommerce (classic CPT + HPOS), plugin version `1.0.2` |
| Python (existing ML) | `psycopg2-binary`, optional `pandas` + `prophet` |

---

## 5. Non-negotiables (violating these breaks the product)

1. **The stock ledger is append-only.** Rows in `sp_item_movements` are never updated or deleted.
   Corrections are *new* rows whose `reversal_of` points at the original.
   ⚠️ The existing Python worker violates the spirit of this by `DELETE`ing derived rows — see §11.6.
2. **Every business row is tenant-scoped by `organization_id`.** Models use the
   `BelongsToOrganization` trait (a global scope + auto-fill). A new component that writes to the DB
   **must** set `organization_id` explicitly — the trait's scope is inert without an authenticated user.
3. **The WooCommerce plugin never writes to WooCommerce.**
4. **Never hand-edit an applied migration, `vendor/`, `node_modules/`, `portal/public/build/` or
   `portal/storage/`.** Add a new migration; `AutoMigrate` applies new files to live installs.

---

## 6. Inventory state machine

Default WooCommerce status → action mapping (overridable per org via the `status_map` setting):

| WooCommerce status | Action | Effect |
|---|---|---|
| `pending`, `on-hold`, `processing`, `confirmed` | **RESERVE** | `quantity_reserved` ↑. Order `reserved`, or `partial` when stock is short. Never blocks the sale. |
| `completed` | **CONFIRM** | `quantity_on_hand` ↓ and `quantity_reserved` ↓. Direct confirm without a prior reserve deducts straight from on-hand. Clamped → order `mismatch` when `allow_negative_stock` is false. |
| `cancelled`, `failed`, `trash` | **RELEASE** | `quantity_reserved` ↓ |
| `refunded`, `returned`, `returned-with-charge` | **RETURN** | `quantity_on_hand` ↑ per refunded line; records `return_charge` |

`available = quantity_on_hand − quantity_reserved`. Order `reservation_state` ∈
`none | reserved | partial | confirmed | released | returned`; order `sync_status` ∈
`pending | processed | unmapped | mismatch | error` (`sp_webhook_events.status` is a separate field:
`received | processing | processed | noop | failed`).

**Multi-level BOM:** `BomExpander` recursively expands a product into stockable entities — the
product's **own** sellable stock plus every recipe line (an `item`, or a sub-`product` recursed), with
`MAX_DEPTH = 10` and branch-cycle detection. A top-level product's *direct item* lines are
**authoritative**: the same item reachable through a sub-product is not added again (the "combo 3×
over-reservation" fix — do not regress it).

---

## 7. Data model

Domain tables are `sp_`-prefixed. `sp_users` is the application user table — **not** `users`.
Framework tables keep Laravel defaults.

### 7.1 Tenancy & identity

| Table | Notes |
|---|---|
| `sp_organizations` | The tenant. `name`, `currency` (e.g. `BDT`), `timezone` (default `UTC`), `low_stock_email` |
| `sp_users` | `organization_id`, `role` ∈ `admin`\|`manager`\|`staff`, `is_active`, `last_login_at` |
| `sp_settings` | Org-scoped JSON config, PK `(organization_id, key)`. Known keys: `allow_negative_stock`, `warning_cooldown_hours`, `status_map` |

### 7.2 Catalog & stock

| Table | Notes |
|---|---|
| `sp_categories` | Hierarchical (`parent_id`), unique `(organization_id, name)` |
| `sp_items` | **Raw components.** `unit`, `cost_price`, `quantity_on_hand`, `quantity_reserved`, `low_stock_threshold`, `is_active`, soft deletes. Unique `(organization_id, sku)` |
| `sp_products` | **Sellable goods.** Same stock columns + `price`, `cost`, `source` (`woo`\|`manual`), `woo_product_id`, `store_id`, `stock_status`, `categories`, `synced_at`. Unique `(store_id, woo_product_id)` |
| `sp_bom_lines` | Recipe. `component_type` ∈ `item`\|`product`; `item_id` **or** `component_product_id` (one is null); `quantity` per unit |

### 7.3 Orders & ledger

| Table | Notes |
|---|---|
| `sp_orders` | `woocommerce_id`, `status`, `reservation_state`, `sync_status`, totals, `return_charge`, `order_created_at` / `order_updated_at`, `payload` JSON. Unique `(organization_id, woocommerce_id)` |
| `sp_order_items` | Line items: `woocommerce_item_id`, `product_id`, `woocommerce_product_id`, `product_name`, `sku`, `quantity`, `price`, `line_total`. Unique `(order_id, woocommerce_item_id)` |
| `sp_order_item_components` | **BOM snapshot per order line** + live state: `quantity_per_unit`, `order_quantity`, `total_quantity`, `reserved_quantity`, `confirmed_quantity`, `returned_quantity`, `status`, `charge_amount`, 4 timestamps |
| `sp_item_movements` | **Append-only ledger** — see §8. No `updated_at` |
| `sp_order_status_events` | Status-change audit trail from webhooks |

### 7.4 Operations / observability

| Table | Notes |
|---|---|
| `sp_webhook_events` | Ingested payloads. Unique `(store_id, event_id)` and `(store_id, nonce)` → dedup |
| `sp_store_connections` | Connected stores. `api_key_hash` (SHA-256), `api_secret_encrypted` (AES-256-CBC), `is_active`, `revoked_at`, `last_seen_at`, `sync_count` |
| `sp_stock_adjustments` | Manual `add`/`remove` with reason + author |
| `sp_stock_warnings` | Warning lifecycle: `active`\|`cleared`\|`dismissed`, `cooldown_until`, `email_sent`. References an item **or** a product |
| `sp_asset_snapshots` | Daily `total_value` + `per_category` JSON — the existing valuation time series |
| `sp_audit_log` | User action log with before/after JSON (`organization_id` nullable) |

### 7.5 ML tables ⭐

Created by `portal/database/migrations/2026_10_10_000001_create_ml_forecasting_tables.php`.
All three use a polymorphic `entity_type` ∈ `'item' | 'product'` + `entity_id` (a plain
`unsignedBigInteger`, **no FK**), and all three carry `organization_id` + `model_version`.

```
sp_demand_forecasts          unique-ish key: (organization_id, entity_type, entity_id, forecast_date)
  forecast_date date · predicted_quantity · lower_bound · upper_bound · model_version
  index: sp_forecasts_lookup_idx

sp_stockout_risks            key: (organization_id, entity_type, entity_id)
  current_stock · daily_burn_rate · days_until_stockout · risk_level(critical|warning|healthy)
  estimated_stockout_date · model_version · calculated_at
  indexes: (organization_id, risk_level), sp_stockout_lookup_idx

sp_restock_recommendations   key: (organization_id, entity_type, entity_id, status='pending')
  recommended_reorder_qty · urgency(high|medium|low) · reasoning text · status(pending|accepted|dismissed)
  index: (organization_id, status, urgency)
```

⚠️ **There is no UNIQUE constraint on any of these keys** — only indexes. Concurrent writers can
duplicate rows. The Laravel path relies on `updateOrCreate`; the Python path uses `DELETE`+`INSERT`.

### 7.6 Framework tables

`users` (unused by the app — **not** `sp_users`), `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`.

---

## 8. The ledger: how to read `sp_item_movements` correctly ⭐

This is the **primary time-series source for any ML work.** Getting the filter wrong silently corrupts
burn-rate features.

Columns: `organization_id`, `item_id` (nullable), `product_id` (nullable), `movement_type`, `qty_delta`,
`reserved_delta`, `on_hand_after`, `reserved_after`, `ref_type`, `ref_id`, `reversal_of`,
`cost_price_at_movement`, `reason`, `created_by`, `created_at`. **No `updated_at`.**

`movement_type` ∈ `initial | purchase_in | adjustment | reserve | confirm | release | return | sync_fix`.

### 8.1 Exact delta semantics (verified in `InventoryEngine`)

| `movement_type` | `qty_delta` | `reserved_delta` | `reversal_of` | Meaning |
|---|---|---|---|---|
| `initial` | `+qty` | `0` | null | Stock on item creation (`ref_type='seed'`) |
| `purchase_in` | `+qty` | `0` | null | Stock received |
| `adjustment` | `±qty` | `0` | null | **Manual** correction (`ref_type='adjustment'`, `ref_id` = item id) |
| `reserve` | **`0`** | `+qty` | null | Reservation only — does **not** reduce on-hand |
| `confirm` | **`−qty`** | `−prevReserved` | null | **Real deduction.** Also written with `qty_delta = 0` on the no-deduct/mismatch path |
| `release` | `0` | `−qty` | → original `reserve` | Reservation freed |
| `return` | **`+qty`** | `0` | → original `confirm` | Restock |
| `sync_fix` | ± | ± | — | Ledger repair tooling (`ledger:cleanup`) |

For order-driven rows: `ref_type = 'order'`, `ref_id = sp_orders.id`.
`reversal_of` is populated **only** for `release` (→ `reserve`) and `return` (→ `confirm`).

### 8.2 The correct consumption query

```sql
-- TRUE component consumption per day (use this for demand features)
SELECT m.organization_id,
       m.item_id,
       date_trunc('day', m.created_at)::date AS ds,
       SUM(ABS(m.qty_delta))                 AS y
FROM sp_item_movements m
WHERE m.movement_type = 'confirm'     -- real deductions only
  AND m.qty_delta < 0                 -- excludes the qty_delta = 0 no-deduct confirm
  AND m.created_at >= NOW() - INTERVAL '90 days'
GROUP BY 1, 2, 3
ORDER BY 1, 2, 3;
```

### 8.3 Pitfalls — do not repeat these

1. **`qty_delta < 0` alone is NOT consumption.** It also matches negative `adjustment` rows (manual
   removals, shrinkage, corrections), inflating the burn rate. **Both existing implementations do this.**
2. **`movement_type = 'confirm'` alone is not enough** either — the no-deduct path writes `confirm`
   with `qty_delta = 0`, which must not count as demand (and must not count as "a day with data").
3. **`reserve` rows have `qty_delta = 0`.** Naive `SUM(qty_delta)` shows nothing; the demand signal
   lives in `reserved_delta`. Reservations also include `partial` orders that were never fulfilled.
4. **Sparse series.** Days with no activity produce **no rows**. Prophet needs a regular daily series —
   **zero-fill the gaps** in Python. Neither current implementation does.
5. **Timezone.** `created_at` is UTC; `sp_organizations.timezone` exists but is ignored when bucketing
   days, so a day boundary may be wrong for non-UTC tenants.
6. **Returns restock.** Net demand over a window should account for `return` rows, otherwise refunded
   volume stays counted as consumption.
7. **Products and items both matter.** `sp_item_movements` now has a nullable `product_id` too
   (multi-level BOM), so the same query shape works for finished goods.

---

## 9. Integration contract: WooCommerce → portal

- **Endpoints:** `POST /api/webhooks/orders`, `POST /api/webhooks/products` (throttled 120/min);
  `POST /api/webhooks/health`, `GET /api/webhooks/test`.
- **Auth:** middleware `store.key`. Headers `X-Api-Key` (looked up by SHA-256 hash),
  `X-Timestamp` (unix, **±300 s window**, else 401), `X-Nonce`, `X-Event-Id`, `X-Signature` =
  `base64(HMAC-SHA256(secret, "{timestamp}:{nonce}:{event_id}:{rawBody}"))`, compared with `hash_equals`.
- **Dedup:** unique constraint violation on `sp_webhook_events` → HTTP **409** (plugin treats 409 as success).
- **Event types:** `order.sync`, `product.sync`, `product.delete`.
- **Payload shape:** `{ "order": { id, number, status, date_created, date_modified, customer{name,email},
  currency, totals{subtotal,discount_total,shipping_total,tax_total,total,refund_total},
  items[{id, product_id, name, sku, quantity, price, line_total}],
  refunds[{id, reason, total, items[{item_id, quantity, total}]}], return_charge? } }`.
  Products: `{ "product": { id, name, slug, sku, type, status, price, regular_price, sale_price,
  stock_status, stock_quantity, categories[{id,name}], image, date_modified }, "delete"?: true }`.
  Full example stream: `portal/tests/fixtures/orders.json`.
- **Modes:** `SP_WEBHOOK_MODE=sync` (default — inline, rolls the event row back on failure so the plugin
  retries) or `queue` (`php artisan queue:work`).
- **Plugin delivery:** local MySQL queue with claim-locking, exponential backoff
  (`min(600, 15·2^(n−1))`), dead-letter after 12 attempts, hourly reconciliation, 409 = drop cleanly,
  401/403 = disable connection + notify admin.
- **Product matching (portal side):** `woo_product_id` → exact `LOWER(TRIM(name))` → `LOWER(TRIM(sku))`,
  for deterministic matching. Unmatched → order `unmapped`, self-heals on a later event.

---

## 10. ML / forecasting subsystem — **current state** ⭐

> This is what already exists. Read this section before designing the Python ML server so you extend
> rather than duplicate.

### 10.1 Component inventory

| Piece | File | Status |
|---|---|---|
| Schema | [portal/database/migrations/2026_10_10_000001_create_ml_forecasting_tables.php](portal/database/migrations/2026_10_10_000001_create_ml_forecasting_tables.php) | Done — 3 tables |
| Models | `portal/app/Models/DemandForecast.php`, `StockoutRisk.php`, `RestockRecommendation.php` | Done — `BelongsToOrganization` + `item()`/`product()` relations |
| API | `portal/app/Http/Controllers/Api/ForecastController.php` | Done — 5 endpoints |
| Routes | `portal/routes/api.php` (group `forecasts`) | Done — see §10.5 |
| PHP pipeline | `portal/app/Console/Commands/GenerateForecasts.php` (`ml:forecast`) | Done — baseline only, **items only** |
| Python worker | [scripts/ml_forecasting_worker.py](scripts/ml_forecasting_worker.py) | Prototype — Prophet/velocity, **items only** |
| UI | `portal/resources/js/pages/Forecasts.tsx` (route `/forecasts`, nav "AI Forecasting" 🔮) | Done — 3 tabs |
| Docs | [FORECASTING.md](FORECASTING.md) | Done |
| Scheduled run | **Not wired** — `ml:forecast` is not in `routes/console.php` | ❌ Missing |
| Product-level forecasting | Schema + UI support it; **no code computes it** | ❌ Missing |
| Accuracy/backtesting, model registry | — | ❌ Missing |

### 10.2 Pipeline A — PHP fallback (`php artisan ml:forecast {--org=}`)

For hosts without Python. Per org, per **item**:

- Reads 30-day consumption: `SUM(ABS(qty_delta)) WHERE qty_delta < 0 AND created_at >= now-30d`.
- `daily_burn = out30 / 30`; `days_left = stock / daily_burn` (null when burn = 0).
- Risk: `critical` if `stock <= 0` or `days_left <= 3`; `warning` if `days_left <= 7` **or**
  `stock <= low_stock_threshold`; else `healthy`.
- Recommendation when critical/warning: `qty = max(daily_burn × 30, 10)`, urgency `high`(critical)/`medium`,
  human-readable `reasoning`.
- Writes 14 daily `sp_demand_forecasts` rows with `lower = 0.8 × burn`, `upper = 1.2 × burn`.
- `model_version = 'baseline-v1'`, via `updateOrCreate` (idempotent).

### 10.3 Pipeline B — Python worker (`scripts/ml_forecasting_worker.py`)

```
python3 scripts/ml_forecasting_worker.py --engine {auto|prophet|velocity} --periods 14 [--dry-run]
```

- Dependencies: `psycopg2-binary`, optional `pandas` + `prophet` (graceful degradation via `HAS_PROPHET`).
- Connections: `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_SSLMODE`
  (⚠️ hardcoded Supabase defaults as module constants — see §11.7).
- Iterates **organizations → items only** (no products).
- History: 60 days of daily sums using the same `qty_delta < 0` filter (§8.3 pitfall #1).
- Model selection: Prophet when `engine ∈ {prophet, auto}` **and** Prophet is importable **and**
  `len(daily_history) >= 5`; otherwise velocity. `used_model = 'meta-prophet-v1'` or
  `'baseline-velocity-v1'`.
- Prophet config: weekly seasonality on, yearly/daily off, `interval_width = 0.95`, predictions clamped
  at ≥ 0. Forward burn = mean of the predicted horizon, then the same risk rules as pipeline A.
- Writes, per entity: **`DELETE` then `INSERT`** into `sp_stockout_risks`,
  `sp_restock_recommendations` (only when critical/warning, `status='pending'` only — accepted/dismissed
  rows are preserved) and `sp_demand_forecasts`.
- One `conn.commit()` for all orgs at the end, `rollback()` in `except` → **all-or-nothing across
  tenants**, and the whole run is wiped out by one bad entity.

### 10.4 Output contract (shared by both pipelines)

| Table | Written fields |
|---|---|
| `sp_stockout_risks` | `current_stock`, `daily_burn_rate`, `days_until_stockout`, `risk_level`, `estimated_stockout_date`, `model_version`, `calculated_at` |
| `sp_restock_recommendations` | `recommended_reorder_qty`, `urgency`, `reasoning`, `status='pending'`, `model_version` |
| `sp_demand_forecasts` | `forecast_date`, `predicted_quantity`, `lower_bound`, `upper_bound`, `model_version` |

Risk thresholds (`3 d` / `7 d`) and the `max(burn × 30, 10)` reorder formula are **duplicated in both
implementations** — if the Python server changes them, it must not silently disagree with the PHP
fallback.

### 10.5 API surface (all under `auth:sanctum`)

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/api/forecasts/summary` | `counts{critical,warning,healthy,urgent_recommendations}`, `top_risks` (5), `last_updated` |
| `GET` | `/api/forecasts/risks` | filters `risk_level`, `entity_type`; ordered critical→warning→healthy, then `days_until_stockout` |
| `GET` | `/api/forecasts/recommendations` | `status` (default `pending`), ordered urgency then `created_at desc` |
| `POST` | `/api/forecasts/recommendations/{id}/action` | body `status ∈ accepted\|dismissed\|pending`; role `manager,admin` |
| `GET` | `/api/forecasts/demand` | required `entity_type` + `entity_id`, ascending `forecast_date` |

Responses enrich each row with `entity_name`, `entity_sku`, `entity_unit` (from `Item`/`Product`).
`ForecastController` is the **pattern to follow**: every query relies on the `BelongsToOrganization`
global scope for tenant isolation, so no explicit `organization_id` filter is needed there.

### 10.6 UI

`portal/resources/js/pages/Forecasts.tsx` — 4 `StatCard`s (critical / warning / healthy / urgent),
tabs for Risks (table with colour-coded badges + runway), Recommendations (approve/dismiss mutations
invalidating `forecast-recs` + `forecast-summary`), and an "engine" tab.
⚠️ **Possible bug:** it calls `apiGet('/api/forecasts/summary')` while the shared client already sets
`baseURL: '/api'` — axios concatenates these into `/api/api/forecasts/summary`. Every other page omits
the prefix (`apiGet('/items', …)`). Verify in the browser and drop the extra `/api` if the page 404s.

### 10.7 Documented status (from FORECASTING.md §6 — **not re-verified here**)

Supabase PostgreSQL 17 target; ~14,890 business records migrated; 10 evaluated items in
`sp_stockout_risks`; 140 forward prediction points in `sp_demand_forecasts`; "76 tests / 345 assertions
passing". ⚠️ PHP/Composer are **not installed in the current dev environment**, so the suite could not be
re-run to confirm that number.

---

## 11. Gaps & technical debt relevant to the Python ML server

1. **Products are never forecast.** The schema, relations and UI all support `entity_type='product'`,
   but both pipelines loop items only. Finished goods are half the business.
2. **BOM-aware demand is absent.** Component demand is inferred only from history. A *better* design
   propagates forecast product demand through `sp_bom_lines` — and `sp_order_item_components` already
   stores the exploded per-order-line requirement, which is the ideal training target.
3. **Signal quality** — the §8.3 pitfalls (`qty_delta < 0` includes adjustments; sparse series not
   zero-filled; UTC day bucketing; returns not netted out).
4. **No accuracy measurement.** No backtesting, no MAE/MAPE, no holdout, no comparison between models.
   `model_version` exists but nothing tracks quality per version.
5. **No model registry / hyperparameter storage.** Prophet is hardcoded; there is no artifact store or
   drift detection.
6. **Destructive write semantics.** The Python worker `DELETE`s and re-`INSERT`s derived rows, and
   `DELETE`s pending recommendations — losing history and any audit trail, and racing with the portal's
   `updateOrCreate`. Prefer idempotent `UPSERT` on the natural keys.
7. **Secrets committed to the repo.** `scripts/ml_forecasting_worker.py` and
   `scripts/migrate_to_supabase.py` carry hardcoded DB credentials as fallbacks, and
   `scripts/build-portal-upload.sh` bakes into the shipped archive a production `APP_KEY`, DB
   credentials **and a live Resend SMTP API key** (added in commit `fcb504f`). These are in git history.
   **Do not copy any of these values into new files**; load from env, and rotate anything real.
8. **No scheduling.** `ml:forecast` is not in `routes/console.php`, and nothing invokes the Python worker.
9. **No observability.** No run logs, no metrics, no alert on a stale `calculated_at`.
10. **Concurrency.** `sp_stockout_risks` / `sp_restock_recommendations` have no unique constraint, so
    two overlapping runs duplicate rows.
11. **Threshold duplication** between PHP and Python (see §10.4).
12. **DTO mismatch.** `sp_stockout_risks.entity_id` has no foreign key and depends on `entity_type`;
    `StockoutRisk::item()`/`product()` both bind `entity_id`, so keep `entity_type` authoritative.

---

## 12. Guidance for the new Python ML server

### 12.1 What it should own

Long-running/compute-heavy work that does not belong in a web request: model training and backtesting,
per-entity forecasting, model registry + artifacts, drift/accuracy monitoring, batch scoring, and any
orbital dependency (Prophet, Pandas, JAX, NeuralForecast, TimesFM) that must not ship in the Laravel bundle.

### 12.2 Boundaries — how to integrate

Prefer **read/write against the shared PostgreSQL database** (the current contract, and why
`DB_CONNECTION=pgsql` is the default) and expose the results through the existing tables so the React UI
keeps working unchanged. Two viable shapes:

- **DB-native (recommended, matches today):** the server reads `sp_*` tables and `UPSERT`s predictions
  back into `sp_demand_forecasts` / `sp_stockout_risks` / `sp_restock_recommendations`. Zero portal
  changes; the existing `/api/forecasts/*` endpoints light up automatically.
- **HTTP sidecar:** add a thin, authenticated internal API on the Python side and have Laravel call it.
  If you take this route you must also add the Laravel-side integration (a service + command) — it does
  not exist yet.

### 12.3 Hard requirements for anything that touches the DB

1. Always scope by `organization_id` — it is the tenant key on every ML table. Never query "all orgs"
   without an explicit loop and a per-org commit boundary.
2. Use the **exact `entity_type`/`entity_id` keys** and keep `model_version` populated (e.g.
   `prophet-v2`, `bom-aware-v1`) so the UI can attribute results.
3. **Be idempotent.** Use `INSERT … ON CONFLICT (organization_id, entity_type, entity_id, forecast_date)
   DO UPDATE` where a unique index exists, and add the missing unique indexes to
   `sp_stockout_risks` / `sp_restock_recommendations` via a **new migration** if you need them.
4. **Never UPDATE or DELETE `sp_item_movements`** (and generally avoid deleting derived rows).
5. **Never write `quantity_on_hand` / `quantity_reserved`** — that is `InventoryEngine`'s job alone.
6. Preserve `sp_restock_recommendations` rows whose `status` is `accepted`/`dismissed`; only refresh
   `pending` ones (the current worker already does this — keep it).
7. Respect PostgreSQL (prod) and SQLite (tests): avoid MySQL-only SQL; use `date_trunc` on PG but keep
   day bucketing portable, or do bucketing in Python.
8. Commit per organization so one failure cannot roll back every tenant.
9. Load credentials from environment variables; never commit them.
10. Reuse the correct consumption definition from §8.2, and net out `return` movements.

### 12.4 Useful modelling inputs already in the DB

| Input | Source |
|---|---|
| Daily component consumption | `sp_item_movements` (`movement_type='confirm'`, §8.2) |
| Per-order-line exploded requirements (ideal training target) | `sp_order_item_components` (`quantity_per_unit`, `total_quantity`, `confirmed_quantity`) |
| Demand lineage item ⇄ product | `sp_bom_lines` (`component_type`, `quantity`) + `sp_bom_lines.component_product_id` |
| Current stock / thresholds / cost | `sp_items`, `sp_products` |
| Order volume, value, status | `sp_orders`, `sp_order_items` |
| History of total asset value | `sp_asset_snapshots` (`total_value`, `per_category`) |
| Manual adjustments (should be excluded from demand, or modelled as a separate regressor) | `sp_item_movements` (`adjustment`) |
| Tenant-local calendar | `sp_organizations.timezone`, `currency` |
| Seasonality regressors | *not present* — no holidays/promotions table exists yet |

### 12.5 Suggested first milestones

1. Replace `scripts/ml_forecasting_worker.py` with a structured service package (config, DB layer,
   feature builder using §8.2, model layer, writers, CLI) — keeping the CLI flags compatible.
2. Add zero-filled daily series + product-level and BOM-aware forecasting.
3. Add backtesting/accuracy reporting and a `model_version` comparison.
4. Add the missing unique indexes (new migration) and switch writes to `UPSERT`.
5. Wire runs to a schedule/cron and emit a run summary the portal can surface (e.g. `last_updated`).

---

## 13. Environment & configuration

Copy `portal/.env.example` → `portal/.env`, then `php artisan key:generate`.

| Variable | Purpose |
|---|---|
| `APP_KEY` | AES key. Auto-generated by `AppServiceProvider::ensureAppKey()` when missing/invalid |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | `APP_DEBUG=false` in production |
| `DB_CONNECTION` (+ `DB_*`, `DB_SSLMODE`) | `.env.example` defaults to `pgsql` (Supabase pooler); `mysql`/`sqlite` also supported |
| `SP_WEBHOOK_MODE` | `sync` (default, no worker) \| `queue` |
| `SESSION_DRIVER`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SANCTUM_STATEFUL_DOMAINS` | Cookie-session SPA auth |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database` — no Redis required |
| `MAIL_MAILER` (+ Resend SMTP in the production bundle) | Threshold alerts and stock digests |

Full table: [README.md §Configuration](README.md). Config is cached in production
(`php artisan config:cache`) — clear/rebuild after changing `.env`.

---

## 14. Commands

All from `portal/` unless noted. **PHP/Composer are not installed in the current environment**; Node and
Python are.

```bash
composer install && npm install
npm run build                  # Vite → portal/public/build (required before serving)
php artisan serve --port=8000  # or: composer dev (serve + queue + logs + vite)
php artisan test               # Pest suite (sqlite :memory:)
npx tsc --noEmit               # SPA type-check — `npm run build` does NOT type-check

php artisan ml:forecast [--org=ID]        # baseline forecasting fallback
php artisan orders:simulate tests/fixtures/orders.json
php artisan ledger:cleanup [--item=] [--product=] [--apply]   # dry run by default
php artisan snapshots:capture | warnings:evaluate | stock:summary
php artisan migrate:fresh

# from the repo root
bash scripts/build-portal-upload.sh    # → portal-upload.zip
bash scripts/build-plugin-upload.sh    # lints PHP → stockpilot.zip
python3 scripts/ml_forecasting_worker.py --engine prophet --periods 14
```

Scheduler (`routes/console.php`): `snapshots:capture` daily 00:05 · `warnings:evaluate` hourly ·
`queue:work --once --stop-when-empty` every minute. Host cron:
`* * * * * php artisan schedule:run`.

### Doc index

Read the relevant guide before changing an area:

[AGENTS.md](AGENTS.md) (repo rules) · [portal/AGENTS.md](portal/AGENTS.md) ·
[portal/app/Services/AGENTS.md](portal/app/Services/AGENTS.md) (domain invariants) ·
[portal/app/Models/AGENTS.md](portal/app/Models/AGENTS.md) ·
[portal/app/Http/AGENTS.md](portal/app/Http/AGENTS.md) ·
[portal/database/AGENTS.md](portal/database/AGENTS.md) ·
[portal/tests/AGENTS.md](portal/tests/AGENTS.md) ·
[portal/resources/js/AGENTS.md](portal/resources/js/AGENTS.md) ·
[portal/routes/AGENTS.md](portal/routes/AGENTS.md) · [portal/config/AGENTS.md](portal/config/AGENTS.md) ·
[stockpilot-for-woocommerce/AGENTS.md](stockpilot-for-woocommerce/AGENTS.md) ·
[scripts/AGENTS.md](scripts/AGENTS.md)

---

## 15. Testing state

- Pest, pointing at SQLite `:memory:` via `phpunit.xml` (`CACHE_STORE=array`, `MAIL_MAILER=array`,
  `QUEUE_CONNECTION=sync`), `RefreshDatabase`, per-file fixture helpers. No factories for domain data.
- Coverage: `InventoryEngineTest`, `MultiLevelBomTest`, `StockIntegrityTest`, `LedgerCleanupTest`,
  `LowStockMailTest`, `ManualOrderStockTest`, `MovementAnalyticsTest`, `ActivityLogTest`,
  `MultiTenantSaaSTest`, `AutoMigrateTest`.
- **No test covers the ML subsystem at all** — neither `ml:forecast` nor the three ML tables. Adding the
  Python server is a good moment to introduce tests (e.g. a seeded ledger → expected risk/forecast).
- `FORECASTING.md` claims 76 tests / 345 assertions passing; unverified in this environment (no PHP).

---

## 16. Security notes

- Webhook HMAC + ±300 s replay window + nonce/event-id dedup; store secrets encrypted at rest
  (AES-256-CBC) on both sides; DB rows are tenant-scoped.
- The plugin requires HTTPS unless `SPW_ALLOW_HTTP` is defined.
- ⚠️ **Committed secrets (see §11.7):** credentials in `scripts/ml_forecasting_worker.py`,
  `scripts/migrate_to_supabase.py`, and a live Resend SMTP API key + `APP_KEY` + DB password in
  `scripts/build-portal-upload.sh`. Rotate them and move to env/vault. When writing new code or docs,
  reference variable names only — never the values.
- `scripts/build-portal-upload.sh` rewrites the archive's `.env` and writes production values; treat the
  generated zip as sensitive.

---

## 17. Glossary

| Term | Meaning |
|---|---|
| **Item** | Raw component / material (`sp_items`) |
| **Product** | Sellable good; may have a recipe and its own finished-goods stock (`sp_products`) |
| **BOM / recipe** | `sp_bom_lines` — components (item or sub-product) consumed per unit |
| **Component** | A resolved order-line requirement; either an item or a sub-product (`sp_order_item_components`) |
| **Ledger** | `sp_item_movements`, append-only stock audit record |
| **Reserve / Confirm / Release / Return** | The four inventory actions driven by WooCommerce status |
| **Available** | `quantity_on_hand − quantity_reserved` |
| **Burn rate** | Estimated units consumed per day |
| **Runway** | `days_until_stockout = current_stock / daily_burn_rate` |
| **`model_version`** | Tag on every ML row identifying what produced it (`baseline-v1`, `meta-prophet-v1`, …) |
| **Store connection** | A connected WooCommerce store with its own API key/secret (`sp_store_connections`) |

---

## 18. Commit history (context for "what's been done")

| Commit | Change |
|---|---|
| `ec49228` / `26c2130` | Initial StockPilot inventory management system |
| `47c3395` | Multi-tenant organizations, movement analytics, dashboard enhancements |
| `7b5c428` | **ML demand forecasting system**, Supabase migration scripts, portal deployment updates |
| `b5a9476` | Agent guide files (`AGENTS.md` tree) |
| `fcb504f` (HEAD) | Ignore EC2 keys; configure Resend SMTP in the portal build script |

Uncommitted at the time of writing: a formatting-only reflow of `FORECASTING.md`.
