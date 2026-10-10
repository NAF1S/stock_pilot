# portal/app/ — Backend application layer

Layering, top to bottom: **Http (thin) → Services (domain) → Models (persistence)**.
Put new feature logic in `app/Services/`; keep controllers, commands and jobs as thin wrappers.

## Folders

| Folder | Role |
|---|---|
| [Http/](Http/AGENTS.md) | API controllers and the middleware (`AuthenticateStoreKey`, `AutoMigrate`, `EnsureRole`) |
| [Models/](Models/AGENTS.md) | Eloquent models (all `sp_*` tables) + `Concerns/BelongsToOrganization` |
| [Services/](Services/AGENTS.md) | All domain logic (inventory engine, BOM, valuation, warnings, forecasting glue) |
| `Jobs/` | `ProcessWebhookEvent` — the single queue entry point for webhook processing |
| `Console/Commands/` | Scheduled / operational Artisan commands |
| `Mail/` | `LowStockAlert`, `StockSummary` (Blade views in `resources/views/mail/`) |
| `Providers/` | `AppServiceProvider` only — auto-generates `APP_KEY` on boot |

## Conventions

- Constructor-inject services (`public function __construct(private SettingService $settings)`);
  resolve them with `app(X::class)` inside jobs and commands.
- Services accept scalars/IDs and return arrays or collections — they never return HTTP responses
  and never call `abort()` except for rule violations the caller expects (`abort(422, '...')`).
- Wrap multi-step stock mutations in `DB::transaction` and lock entities in ascending `id` order
  (see [Services/AGENTS.md](Services/AGENTS.md)).
- Never write `quantity_on_hand` / `quantity_reserved` outside `InventoryEngine`.

## Artisan commands

| Command | Purpose |
|---|---|
| `snapshots:capture` | Nightly asset-value snapshot (scheduled daily 00:05) |
| `warnings:evaluate` | Re-evaluate low-stock warnings (scheduled hourly) |
| `stock:summary` | Email each org a daily digest — warning when low, else full report |
| `orders:simulate {file?}` | Replay a JSON order-event fixture through the engine |
| `ledger:cleanup [--item=] [--product=] [--apply]` | Detect/repair duplicate-confirm phantom ledger rows (dry run by default) |
| `ml:forecast {--org=}` | Baseline burn-rate / stockout / demand forecast without Python |

Deploy or bootstrap flow: [AppServiceProvider](../app/Providers/AppServiceProvider.php) and
`Http/Middleware/AutoMigrate.php` make the app self-installing; keep them working on an empty database.
