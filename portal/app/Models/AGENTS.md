# portal/app/Models — Eloquent models

One model per `sp_*` table. `protected $table` is always declared explicitly.

## Conventions

- Fillable is declared with the PHP attribute, **not** the `$fillable` property:

  ```php
  #[Fillable(['organization_id', 'name', 'sku', 'unit', 'cost_price'])]
  class Item extends \Illuminate\Database\Eloquent\Model
  ```

- Casts use the `protected function casts(): array` method, not `$casts`.
- Computed values are `Attribute::get()` / `Attribute::set()` accessors — e.g. `Item::available`
  (`quantity_on_hand - quantity_reserved`), `Item::assetValue`, `Product::stockValue`,
  `StoreConnection::apiKey` (hashes on set) and `StoreConnection::apiSecret` (encrypts on set,
  decrypts on get).
- Money/quantity columns are `decimal(14,4)` in the schema and `float` in casts; round to 4 decimals.

## Multi-tenancy: `Concerns/BelongsToOrganization`

Used by 14 of the 21 models (see the caveats below). It adds:

- a global scope named `organization` that filters `organization_id = Auth::user()->organization_id`, and
- an auto-fill of `organization_id` on create when a user is authenticated.

Consequences to keep in mind:

- Web requests are tenant-filtered automatically. `Model::withoutGlobalScope('organization')` is the
  escape hatch (tests use it to read another tenant's row).
- Jobs, commands and services run **without** an authenticated user, so the scope is inert there — they
  must filter by `organization_id` themselves.
- The trait is on 14 models (`AssetSnapshot`, `BomLine`, `Category`, `DemandForecast`, `Item`,
  `ItemMovement`, `Order`, `Product`, `RestockRecommendation`, `StockAdjustment`, `StockoutRisk`,
  `StockWarning`, `StoreConnection`, `WebhookEvent`). The seven that **do not** have it each need care:
  `Organization` *is* the tenant; `User`, `Setting` and `OrderItemComponent` carry `organization_id` but are
  not auto-scoped; `OrderItem` and `OrderStatusEvent` have no `organization_id` at all and are only reachable
  through their parent order; `AuditLog::organization_id` is nullable.

## Tables

| Model | Table | Notes |
|---|---|---|
| `Organization` | `sp_organizations` | Tenant, currency, `low_stock_email` |
| `User` | `sp_users` | Roles: `admin`, `manager`, `staff`; `isActive()`/`hasRole()` |
| `StoreConnection` | `sp_store_connections` | API key hash + encrypted secret, sync counters |
| `Category` | `sp_categories` | Hierarchical (self-referencing) |
| `Item` | `sp_items` | Raw components; SoftDeletes |
| `Product` | `sp_products` | Sellable goods + Woo catalog fields; SoftDeletes |
| `BomLine` | `sp_bom_lines` | Recipe line → `item` or `component_product` (`component_type`) |
| `ItemMovement` | `sp_item_movements` | Append-only ledger; `reversal_of`, `reversedBy()` |
| `Order` | `sp_orders` | `reservation_state` + `sync_status` |
| `OrderItem` | `sp_order_items` | Woo line items |
| `OrderItemComponent` | `sp_order_item_components` | Per-line component snapshot with reservation status |
| `OrderStatusEvent` | `sp_order_status_events` | Status-change audit trail |
| `WebhookEvent` | `sp_webhook_events` | Ingested payloads + dedup keys |
| `StockAdjustment` | `sp_stock_adjustments` | Manual add/remove with reason and user |
| `StockWarning` | `sp_stock_warnings` | Low-stock warning lifecycle (item **or** product) |
| `AssetSnapshot` | `sp_asset_snapshots` | Time-series total + per-category valuation |
| `AuditLog` | `sp_audit_log` | User action log with before/after JSON |
| `Setting` | `sp_settings` | Org-scoped JSON config (composite PK) |
| `DemandForecast` / `StockoutRisk` / `RestockRecommendation` | `sp_demand_forecasts` / `sp_stockout_risks` / `sp_restock_recommendations` | ML output, `entity_type` + `entity_id` |

## Rules

- Never write `quantity_on_hand` / `quantity_reserved` directly — go through
  `App\Services\InventoryEngine` so a ledger row is written and locks are taken in the right order.
- A new column means: migration + `#[Fillable]` entry + cast (+ payload if the UI needs it).
  See [../../database/AGENTS.md](../../database/AGENTS.md).
