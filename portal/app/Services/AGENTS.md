# portal/app/Services — Domain logic

All inventory rules live here. Controllers, commands and jobs stay thin.
Every multi-step stock change runs inside `DB::transaction`, locks entities in ascending `id` order,
and appends to the ledger.

## InventoryEngine — the core state machine

`processOrderEvent(array $orderData, StoreConnection $store, WebhookEvent $event)` is called by
`Jobs\ProcessWebhookEvent`. Manual equivalents exist for the UI: `manualReserve`, `manualConfirm`,
`manualRelease`, `manualConfirmWithoutDeduction`.

Default status mapping (per-org overrides live in the `status_map` setting):

| WooCommerce status | Action | Effect |
|---|---|---|
| `pending`, `on-hold`, `processing`, `confirmed` | **RESERVE** | `quantity_reserved` up; order `reserved`, or `partial` when stock is short |
| `completed` | **CONFIRM** | `quantity_on_hand` and `quantity_reserved` down; direct confirm deducts without a prior reserve; clamped/`mismatch` when `allow_negative_stock` is false |
| `cancelled`, `failed`, `trash` | **RELEASE** | `quantity_reserved` down |
| `refunded`, `returned`, `returned-with-charge` | **RETURN** | `quantity_on_hand` up per refunded line; records `return_charge` |

Load-bearing invariants:

1. One transaction per event; the `Order` row is `lockForUpdate()`-ed first.
2. Entities are locked in **ascending id order** — `->orderBy('id')->lockForUpdate()` across both items
   and products — to avoid deadlocks between concurrent orders.
3. Idempotency: before reserving/confirming, `sp_item_movements` is consulted for existing movements for
   that `(order, entity)`, so replayed webhooks never double-reserve or double-deduct.
4. Every change appends a ledger row via the private `ledger()` helper; corrections reference the original
   through `reversal_of`. Ledger rows are never edited.
5. Product matching order is `woo_product_id` → exact name (lower/trim) → exact SKU, always `orderBy('id')`
   for determinism. Unmatched lines leave the order `unmapped` and it self-heals once the product exists.

## BomExpander

`expandForOrder(Order)` returns `rows` (per component: `quantity_per_unit`, `total_quantity`),
`unmapped` order-item ids and a `cycle_error` flag.

- Recurses sub-products with `MAX_DEPTH = 10` and branch-path cycle detection.
- Every order line also consumes the **sellable product's own finished-goods stock**.
- A top-level product's *direct item* lines are authoritative: the same item reachable through a
  sub-product is not added again (this is the combo over-reservation fix — keep it).
- `productCost()` returns per-unit material cost.

## Other services

| Service | Responsibility |
|---|---|
| `AssetValuation` | `total()`, `byCategory()`, `captureSnapshot()` — sum of on-hand × cost |
| `LowStockNotifier` | Creates/clears `StockWarning` rows and sends `LowStockAlert` mail **synchronously** (shared hosting has no worker). One email per threshold crossing; `warning_cooldown_hours` suppresses re-sends; `email_sent` stays false on failure so the next evaluation retries |
| `MovementAnalyticsService` | Ledger analytics, period buckets (hour/day/month), dashboard summary |
| `OrderRecomposer` | `recompose($order, $apply)` — recipe drift + component cache reconciliation; `$apply = false` is a dry run. Powers the Orders Recompute/Reprocess buttons |
| `ProductSyncProcessor` | `syncProduct($data, $store, $isDelete)` — creates/updates `Product` rows from catalog webhooks |
| `SettingService` | Org-scoped JSON settings in `sp_settings` (`get`/`set`/`all`). Known keys: `allow_negative_stock`, `warning_cooldown_hours`, `status_map` |
| `SetupService` | First-run wizard: test MySQL, rewrite `DB_*` in `.env`, create the organization + admin |

## Tests

Behaviour is pinned by [../../../tests/AGENTS.md](../../tests/AGENTS.md) — run `php artisan test`
after touching any service. The most relevant files are `InventoryEngineTest`, `MultiLevelBomTest`,
`StockIntegrityTest`, `LedgerCleanupTest` and `LowStockMailTest`.
