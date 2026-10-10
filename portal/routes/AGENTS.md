# portal/routes — URL map

`api.php` holds every API route (registered with the `/api` prefix in
[../bootstrap/app.php](../bootstrap/app.php)). `web.php` only serves the SPA shell, and `console.php`
holds the scheduler. See [../app/Http/AGENTS.md](../app/Http/AGENTS.md) for controller conventions.

## Groups in `api.php`

| Group | Middleware | Notes |
|---|---|---|
| `setup/*` | none | Self-install wizard. Must stay reachable on an empty/unconfigured database |
| `auth/*` | none (`auth:sanctum` on `me` / `logout`) | Cookie-session register/login/logout and the password reset link |
| `webhooks/*` | `store.key` (`+ throttle:120,1` on `orders` and `products`) | Plugin-facing; authenticated by HMAC in `AuthenticateStoreKey` |
| everything else | `auth:sanctum` | The SPA API |

Route areas under `auth:sanctum`: `dashboard`, `users` (admin only), `items`, `categories`,
`products` (+ `bom`, `cost-summary`, `validate`), `orders` (incl. `bulk`, `recompute`, `reprocess`,
`reserve`, `confirm`, `release`, `confirm-no-deduct`), `movements` (+ `movements/analytics`),
`reports/*`, `stores/*`, `settings`, `forecasts/*`.

## Authorization

Role checks are per-route middleware using the `role` alias registered in `bootstrap/app.php`:

- reads that staff may do → no role middleware,
- writes → `->middleware('role:manager,admin')`,
- deletes and user/store management → `->middleware('role:admin')`.

`EnsureRole` aborts 401 (no user) or 403 (wrong role). The SPA also hides controls by role, but the API
must enforce independently — always add the matching `role:` middleware to a new write/delete route.

## Conventions

- Add a route to the correct group rather than creating a new prefix, and keep the resource-style ordering
  (`bulk` before `{order}`, literal segments before parameters) so `{model}` bindings don't swallow them.
- Tenant isolation comes from route-model binding plus the controller's `assertSameOrg()`, which returns
  **404** for another tenant's record — don't change it to 403.
- `web.php` must keep aborting `api/*` and `sanctum/*`; otherwise the catch-all would render the SPA (HTML)
  where the API should return JSON.
- URLs in the SPA are relative to `/api` (see [../resources/js/AGENTS.md](../resources/js/AGENTS.md)).

## Scheduler (`console.php`)

| Schedule | Command |
|---|---|
| `dailyAt('00:05')` | `snapshots:capture` |
| `hourly()` | `warnings:evaluate` |
| `everyMinute()->withoutOverlapping()` | `queue:work --once --stop-when-empty` |

The host must run `* * * * * php artisan schedule:run` for any of these to fire.
