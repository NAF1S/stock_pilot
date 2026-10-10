# portal/tests — Pest test suite

```bash
php artisan test                       # whole suite
php artisan test --filter=Inventory    # filter by test/file name
composer test                          # clears config, then runs the suite
```

## Setup

- [Pest.php](Pest.php) binds `Tests\TestCase` to both the `Feature` and `Unit` suites.
- `phpunit.xml` sets SQLite `:memory:`, `CACHE_STORE=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`
  and `SESSION_DRIVER=array` — tests never touch a real database or mailbox.
- Feature tests call `uses(RefreshDatabase::class);`.
- Two styles coexist: Pest functional tests (`test('...', function () { ... })`) in most files, and PHPUnit
  classes (e.g. `class MultiTenantSaaSTest extends TestCase`) in the older ones. Prefer Pest for new files.

## Conventions

- Fixtures are **per-file helper functions** declared at the top of the test file — `engineFixture()`,
  `engineOrder()`, `baseOrder()` in `InventoryEngineTest.php` return `[$org, $store, $items, $product]` and
  build items, a store connection and a BOM by hand. Add a new local helper instead of mutating a shared one.
- `BelongsToOrganization` adds a global scope, so use `Model::withoutGlobalScope('organization')` when a test
  must see another tenant's rows (this is the pattern the multi-tenant tests rely on).
- Assertions in use: `assertStatus`, `assertJsonPath`, `assertJsonFragment`, `assertJsonMissing`,
  `assertJsonCount`, `assertJsonValidationErrors`, `assertDatabaseHas`, and
  `Mail::fake()` + `Mail::assertSent` / `assertNotSent`.
- `fixtures/orders.json` is an ordered stream of WooCommerce order events (reserve → confirm → cancel →
  return-with-charge) consumed by `php artisan orders:simulate`; extend it when you change engine semantics.

## What each file covers

| File | Covers |
|---|---|
| `Feature/InventoryEngineTest.php` | Reserve/confirm/release/return, duplicate events, out-of-order completion, partial reserve, ledger + reversal pointers, custom status map, low-stock warning/email on reserve |
| `Feature/MultiLevelBomTest.php` | Recursive BOM, combo de-duplication, finished-goods consumption, cycle detection, product matching, bulk reprocess/delete, settings test-email |
| `Feature/StockIntegrityTest.php` | No re-deduction when component rows reappear, recovering a negative balance, `422` on over-removal |
| `Feature/LedgerCleanupTest.php` | Phantom duplicate-confirm detection/repair, legitimate cycles untouched |
| `Feature/LowStockMailTest.php` | Warning lifecycle, cooldown + retry, `stock:summary` warning vs report |
| `Feature/ManualOrderStockTest.php` | Manual reserve/confirm/release, confirm-without-deduction, bulk actions, role guard |
| `Feature/MovementAnalyticsTest.php` | Timeline/breakdown analytics, inflow/outflow semantics, tenant isolation, dashboard low-stock counts |
| `Feature/ActivityLogTest.php` | Movement listing/filters, org scoping, asset report totals |
| `Feature/MultiTenantSaaSTest.php` | Registration, validation, and cross-tenant isolation (404 on foreign records) |
| `Feature/AutoMigrateTest.php` | Pending migrations auto-applied to an existing install |
| `Feature/ExampleTest.php`, `Unit/ExampleTest.php` | Framework smoke tests |
