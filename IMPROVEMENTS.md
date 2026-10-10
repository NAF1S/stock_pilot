# StockPilot — Improvement Backlog

Recommendations produced from a live diagnosis of the production instance
(`ubuntu@13.212.60.198`, `https://apigateway.live`) on **2026-10-10**, after the
"settings page did not save user input" incident and the subsequent slowdown.

Work top to bottom. **P0 items are time-sensitive** (secrets exposure); P1 items
decide whether the inventory engine actually honours what the operator types;
P2/P3 are reliability, performance and polish.

Every item lists the exact files, the evidence, the recommended change, and how
to verify it. Tick the box when done.

## Status

- [ ] P0-1 — Purge production data & store secrets from git
- [ ] P0-2 — Stop the dev machine pointing at the production database
- [ ] P1-1 — Block empty `status_map` fields from silently disabling inventory actions
- [ ] P1-2 — Surface unmapped / unknown order statuses instead of silently no-op'ing
- [ ] P1-3 — Reject a status listed in more than one action
- [ ] P1-4 — Review the live status map (`confirmed` and others are unmapped)
- [ ] P1-5 — Validate `status_map` shape server-side
- [ ] P1-6 — Run the test suite against Postgres in CI
- [ ] P2-1 — Stop scanner traffic from saturating PHP-FPM
- [ ] P2-2 — Cache the `AutoMigrate` schema check
- [ ] P2-3 — Right-size / tune the instance memory
- [ ] P2-4 — Make the deploy ship sources and build consistently
- [ ] P2-5 — Return 401 (not 500) for unauthenticated API calls
- [ ] P2-6 — Alert on 5xx responses
- [ ] P3-1 — Deployment/rollback runbook for the portal

## Summary

| ID   | Item | Area | Severity | Effort |
|------|------|------|----------|--------|
| P0-1 | Production dump + secrets in git history | Security | Critical | M |
| P0-2 | Dev `.env` → production Supabase DB | Security | High | S |
| P1-1 | Empty map field disables an action silently | Correctness | High | S |
| P1-2 | Unknown statuses silently ignored | Correctness | High | M |
| P1-3 | Status duplicated across actions | Correctness | Medium | S |
| P1-4 | Live map has `confirmed` unmapped | Correctness | Medium | S |
| P1-5 | No server-side `status_map` validation | Correctness | Low | S |
| P1-6 | Tests never run on Postgres | Process | High | M |
| P2-1 | Bot flood saturates PHP-FPM | Performance | High | S |
| P2-2 | `AutoMigrate` DB round-trips per request | Performance | Medium | S |
| P2-3 | 405 MB instance, OOM events | Reliability | Medium | M |
| P2-4 | Stale frontend sources on the server | Reliability | Low | S |
| P2-5 | Guest API returns 500 instead of 401 | Correctness | Low | S |
| P2-6 | No 5xx alerting | Ops | Medium | M |
| P3-1 | Documented deploy/rollback runbook | Ops | Low | M |

Severity: Critical / High / Medium / Low. Effort: S (≤1 h), M (half day), L (multi-day).

---

## Context: the 2026-10-10 incident (already resolved)

Recorded here so the reasoning behind P1-1…P1-6 is clear.

**Symptom:** operators saved the Settings page but nothing took effect.

**Root cause:** `sp_settings` has a composite primary key and **no `id` column**
(`portal/database/migrations/2026_08_01_000007_create_ops_tables.php:66` →
`$table->primary(['organization_id', 'key'])`). `SettingService::set()`
(`portal/app/Services/SettingService.php:16`) calls `Setting::updateOrCreate()`;
on an **existing** row that runs `save()`, and Eloquent — unaware of the
composite key — emitted:

```sql
update "sp_settings" set "value" = {...} where "id" is null
```

Postgres rejected it (`SQLSTATE[42703] column "id" does not exist`) → HTTP 500 →
no settings were written. First-ever inserts worked, which is why it slipped
through; and on SQLite it fails softly, so the sqlite test suite never caught it.

**Fix (commit `8bdc78f`, deployed and verified):**
`Setting::setKeysForSaveQuery()` (`portal/app/Models/Setting.php:38`) scopes the
update to `organization_id + key`.

**Verification performed:** deployed PHP matches HEAD byte-for-byte (all 90 files
under `app/ config/ routes/ database/`); live DB transactional probe (rolled back)
returned `UPDATE_PATH_OK`; the live DB now holds the saved map; 7/7 `SettingTest`
and 82/82 full suite pass.

> Note: the fix is what *unblocked* the status map. Until it was deployed the
> inventory engine was always running on its hard-coded defaults — the operator's
> rules were never being applied.

---

## P0 — Security

### P0-1 · Purge production data and store secrets from git

**Severity:** Critical · **Effort:** M

**Problem.** `scratch/supabase_import.sql` (4.6 MB) is **tracked in git**. It is a
full production dump and contains at least:

- `INSERT INTO "sp_users" (... "password" ...)` — user emails + bcrypt hashes
- `INSERT INTO "sp_store_connections" (... "api_key" ...)` — the WooCommerce
  webhook **HMAC secrets** used to authenticate store webhooks
- order, item and movement data

The GitHub repo appears **private** (`https://api.github.com/repos/NAF1S/stock_pilot`
returns 404 to an unauthenticated request), so this is not a public leak today —
but production credentials in version history are one collaborator, one
visibility change, or one clone away from exposure, and they survive in history
even after the file is deleted in a later commit.

**Recommendation.**

1. Add `scratch/*.sql` (and keep `hidehave_spdb.sql`, `ec2 keys.pem`, `*.zip`) to
   `.gitignore` — the latter three are already ignored.
2. Remove the file from the working tree and rewrite history to drop it
   (`git filter-repo --path scratch/supabase_import.sql --invert-paths`, or BFG).
   Coordinate: history rewrite requires force-pushing `main` and everyone
   re-cloning.
3. **Rotate what leaked**, in this order:
   - Rotate every store connection `api_key` (the plugin's webhook signing
     secret) — this requires reconnecting/pairing each store in the portal.
   - Treat user password hashes as compromised: force a password reset on
     next login, or, more simply, invalidate sessions and require reset.
4. Confirm the repo should stay private; consider a deploy key / fine-grained
   token instead of a personal SSH key.

**Verify.** `git log --all --oneline -- scratch/supabase_import.sql` returns
nothing after the rewrite; `git ls-files | grep -iE '\.sql$|\.pem$|\.env$'`
shows only `portal/.env.example`; stores re-pair successfully after rotation.

---

### P0-2 · Stop the dev machine pointing at the production database

**Severity:** High · **Effort:** S

**Problem.** `portal/.env` on the development machine has
`DB_HOST=[redacted]` pointing at the **production**
database. Any destructive local command (`migrate:fresh`, a test that forgets
`RefreshDatabase` isolation, a stray tinker session) hits live customer data.
`phpunit.xml` correctly overrides to `sqlite :memory:`, so the test suite is safe
today — but the `php artisan` CLI is one mistake away.

**Recommendation.** Provision a separate Supabase project (or a local Postgres /
sqlite file) for development and point `portal/.env` at it. Keep production
credentials only in the server's `.env` and the build script.

**Verify.** `grep DB_HOST portal/.env` no longer resolves to the production
project ref; `php artisan migrate:fresh` locally cannot affect production.

---

## P1 — Inventory rule correctness

These are the "the operator typed a rule and it must actually apply" items.
Background: `InventoryEngine::statusMap()`
(`portal/app/Services/InventoryEngine.php:275`) reads `sp_settings.status_map`;
`resolveAction()` (`:286`) maps the incoming WooCommerce status to an action;
`runAction()` (`:333`) dispatches to `reserve()` / `confirm()` / `release()` /
`restock()`. So the map *is* authoritative for reserve, confirm, release and
return — the failure modes below are all **silent**, which is the real problem.

### P1-1 · Block empty `status_map` fields from silently disabling inventory actions

**Severity:** High · **Effort:** S

**Problem.** `statusMap()` falls back to built-in defaults with `??`, which only
triggers on `null`:

```php
return (array) ($this->settings->get($organizationId, 'status_map') ?? [ /* defaults */ ]);
```

The Settings UI always submits all four keys
(`portal/resources/js/pages/Settings.tsx`), so if an operator **clears one field**
it is saved as `[]` — an empty *array*, not `null`. The default never kicks in and
that action becomes permanently `noop`. Concretely: clear **Release reservation**
and cancelled orders will never free reserved stock again. Clear all four and the
engine stops processing every order. No error is shown anywhere.

**Recommendation.** Two complementary guards:

1. Frontend: treat an all-blank field as "use default" (send `null`/omit), or
   block saving with a validation message.
2. Backend: in `statusMap()`, merge the stored map over the defaults and treat
   empty/missing keys as "use default"; and normalise a fully-empty map to the
   defaults. Reject a partial map that would drop an action entirely — or at
   least log a warning.

**Verify.** In Settings, clear the "Release reservation" field, save, then push a
`cancelled` order through and confirm reserved stock is released (or that the save
is rejected with a clear message).

### P1-2 · Surface unmapped / unknown order statuses instead of silently no-op'ing

**Severity:** High · **Effort:** M

**Problem.** `resolveAction()` (`InventoryEngine.php:286`) returns `null` for any
status that appears in no list, and `processOrderEvent` treats `null` as `noop`
(records it, changes no stock). A typo in the Settings field, or a custom status
an order-status plugin introduced, means orders silently do nothing — no warning,
no dashboard signal. This is the same class of silent failure as the original bug.

**Recommendation.**

- Log unmapped statuses at `warning` level with the order id and status.
- Surface them in the UI: e.g. a badge/count on the Orders page ("3 orders in an
  unmapped status: `shipped`, `confirmed`") and on the Dashboard.
- Optionally record the incoming-but-unmapped status on the order so it is
  queryable.

**Verify.** Push an event with a status not in the map; confirm a warning log line
and a visible indicator in the portal, and no stock change.

### P1-3 · Reject a status listed in more than one action

**Severity:** Medium · **Effort:** S

**Problem.** `resolveAction()` iterates the map and takes the **first** action
whose list contains the status. Iteration order follows the saved JSON
(reserve → confirm → release → return). If an operator puts `processing` in both
"Reserve stock" and "Deduct stock", reserve silently wins and the deduction never
happens — with no indication.

**Recommendation.** Validate in the Settings UI and on the server (P1-5) that a
status appears in exactly one list, and reject the save with a message naming the
duplicate.

**Verify.** Type the same status into two fields; the save is rejected with a
clear message.

### P1-4 · Review the live status map (`confirmed` and others are unmapped)

**Severity:** Medium · **Effort:** S

**Problem.** The live map for the organisation is currently:

```json
{"reserve":["pending","processing"],"confirm":["completed"],
 "release":["cancelled","failed"],"return":["refunded"]}
```

Everything else is unmapped and therefore ignored, notably **`confirmed`** —
which the application's own default treats as a *reserve* trigger
(`InventoryEngine.php:277`) and which the UI explicitly calls out as a common
custom status an order-status plugin may add. `on-hold`, `pending-payment`,
`trash`, `returned` and `returned-with-charge` are also unmapped.

**Recommendation.** Confirm with the store owner which statuses WooCommerce
actually emits, then set the map accordingly. If `confirmed` is in use and it is
meant to reserve stock, add it to "Reserve stock". Re-check whenever an
order-status plugin is added or renamed.

**Verify.** List the distinct order statuses seen in webhook events and confirm
each one is mapped to the intended action.

### P1-5 · Validate `status_map` shape server-side

**Severity:** Low · **Effort:** S

**Problem.** `SettingController::update()` validates only `'status_map' => 'sometimes|array'`
(`portal/app/Http/Controllers/Api/SettingController.php:46`). The shape
(`reserve|confirm|release|return` → array of non-empty strings), unknown keys and
duplicates are unchecked, and `allow_negative_stock` / `warning_cooldown_hours`
are written without a bounds re-check.

**Recommendation.** Validate explicitly, e.g.
`status_map` = array with only those four keys, each an array of lowercase
non-empty strings, no status repeated across lists; `warning_cooldown_hours`
integer 0–8760 (already `min:0` — add a `max`).

**Verify.** POST a malformed map (unknown key, duplicate status, non-string
value) and confirm a 422 with a useful message.

### P1-6 · Run the test suite against Postgres in CI

**Severity:** High · **Effort:** M

**Problem.** `phpunit.xml` pins `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
The composite-key bug that caused the incident is **Postgres-specific** — SQLite
fails softly where Postgres raises `42703`. A suite that only exercises SQLite
cannot catch this class of regression, so the same bug could ship again.

**Recommendation.** Add a CI job (GitHub Actions) that runs the Pest suite against
Postgres 16 as well as SQLite — a service container is enough, no Supabase needed.
Optionally add a focused test asserting `sp_settings` has no `id` column and that
saving then re-saving a setting actually persists the new value.

**Verify.** CI runs both matrices; temporarily reverting the `setKeysForSaveQuery`
override makes the Postgres job fail.

---

## P2 — Reliability, performance and operations

### P2-1 · Stop scanner traffic from saturating PHP-FPM

**Severity:** High · **Effort:** S

**Problem.** On 2026-10-10 at 15:50 a single IP (`34.10.194.162`) sent **311
requests in one minute** probing `/.env`, `/.gitconfig`, `/.ssh/authorized_keys`,
etc. Each hit reaches Laravel's catch-all route and boots the whole framework.
PHP-FPM is `pm = ondemand` with **`pm.max_children = 5`** on a 406 MB instance, so
the pool filled and the log recorded
`WARNING: [pool www] server reached max_children setting (5)` — genuine user
requests queued behind the flood. (Normal traffic is ~6 req/min; this was the
outlier.) Do not paper over it by raising `max_children` — memory is the binding
constraint (see P2-3).

**Recommendation.**

- In nginx: a `limit_req` zone applied to `/` (e.g. `10r/s` burst `20`), plus a
  fast 444/403 for the obvious probe paths (`~* /\.(env|git|ssh|aws|boto|s3cfg)`
  and friends — several are already denied by rule, which is only generating log
  noise).
- Consider `fail2ban` on nginx 403s, and/or putting Cloudflare in front for
  bot management and caching of static assets.
- Confirm `portal/public/build/*` is served with long cache headers (hashed
  filenames make this safe) so static assets never reach PHP.

**Verify.** Re-run a scanning burst (e.g. `ab`/`hey` against random paths) and
confirm real requests stay fast and `max_children` warnings stop.

### P2-2 · Cache the `AutoMigrate` schema check

**Severity:** Medium · **Effort:** S

**Problem.** `AutoMigrate` (`portal/app/Http/Middleware/AutoMigrate.php`) runs on
**every** request (registered in `bootstrap/app.php` for both `web` and `api`) and
performs two remote Supabase round-trips: `Schema::hasTable('sp_organizations')`
(~10 ms) and the migrator's `getRan()` (~12 ms). Measured ~21 ms per request
against a database in the same region — worse if the pooler is cold.

**Recommendation.** Short-circuit the check: store a fingerprint (e.g. the newest
migration filename + a schema version) in `bootstrap/cache/` or the app cache and
only query the database when it changes. Keep the fresh-install path intact.

**Verify.** Time a request before/after; confirm a newly added migration still
applies on deploy (`AutoMigrate` test in `tests/Feature/AutoMigrateTest.php`
should keep passing).

### P2-3 · Right-size / tune the instance memory

**Severity:** Medium · **Effort:** M

**Problem.** The instance has **405 MB RAM, 2 vCPU**, 2 GB swap (80 MB in use),
and regular kernel OOM events (historically killing `fwupd`, so far nothing
critical). Nginx + PHP-FPM + system already consume most of it. Memory is why
`pm.max_children` is 5 — raising it risks OOM-killing PHP workers under load.

**Recommendation.** Either move to a larger instance (1–2 GB) or, if staying
small: verify OPcache is warm for the FPM SAPI (it is enabled —
`opcache.enable=On`, `memory_consumption=128`), consider OPcache preloading,
keep `pm = ondemand` with a low `max_children`, and set
`pm.max_requests` (already 500) to bound leaks. Add swap-pressure alerting.

**Verify.** Load-test and confirm no OOM kills and no swap thrashing
(`vmstat 1` over the test window).

### P2-4 · Make the deploy ship sources and build consistently

**Severity:** Low · **Effort:** S

**Problem.** The 2026-10-10 deploy updated `portal/app/**` and
`portal/public/build/**` but left `portal/resources/js/**` at the older revision.
Runtime is unaffected (the built bundle is current and matches HEAD, and the
`manifest.json` points at it), but the server's sources no longer match what is
served — so a future `npm run build` **on the server** would silently revert the
frontend settings fix. The server's PHP tree, by contrast, matches HEAD exactly
(all 90 files), so this is specifically a frontend-source gap.

**Recommendation.** Pick one strategy and script it: either build in CI and
deploy only artefacts (then do not ship `resources/` at all), or deploy the full
tree and build on the server. Avoid the current hybrid. Record the deployed
commit on the server (e.g. write `REVISION` during deploy) so drift is detectable.

**Verify.** After the next deploy, `md5sum` the same file set on server and in
`git show HEAD` and confirm they match, including `resources/js/`.

### P2-5 · Return 401 (not 500) for unauthenticated API calls

**Severity:** Low · **Effort:** S

**Problem.** A guest request to an API route **without** `Accept: application/json`
or `X-Requested-With` returns **500 `Route [login] not defined`** instead of 401.
Reproduced locally. Laravel 11's default `Authenticate::redirectUsing(fn () => route('login'))`
fires and there is no named `login` route (only `api/login`). The SPA always sends
those headers, so users are unaffected — but every such probe writes a 500 into
`laravel.log`, which is exactly the noise that makes real incidents hard to spot.

**Recommendation.** In `bootstrap/app.php` set
`$middleware->redirectGuestsTo(fn () => null);` (the handler then returns a clean
401), or define a named `login` route. The existing
`shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` is registered but does not cover
this path because the redirect callback throws before the exception is rendered.

**Verify.** `curl -s -o /dev/null -w "%{http_code}" https://apigateway.live/api/settings`
returns 401, and no new `Route [login] not defined` entries appear in the log.

### P2-6 · Alert on 5xx responses

**Severity:** Medium · **Effort:** M

**Problem.** The incident's 500s sat in `portal/storage/logs/laravel.log`
(`LOG_LEVEL=error`) unnoticed until someone looked. The server has no 5xx
alerting.

**Recommendation.** Add an uptime/error monitor (UptimeRobot, Better Stack, or a
small cron that greps nginx/Laravel logs) that alerts on a 5xx spike or on the
site being slow. If you add Sentry or similar, wire `LOG_CHANNEL` accordingly.

**Verify.** Force a 500 on a staging URL and confirm an alert arrives.

---

## P3 — Polish

### P3-1 · Documented deploy / rollback runbook for the portal

**Severity:** Low · **Effort:** M

**Problem.** `DEPLOYMENT.md` covers shared hosting and VPS generally, but the
actual live path (build `portal-upload.zip` → upload → unzip over
`/var/www/stockpilot/portal` → reload PHP-FPM) is tribal knowledge, which made
this incident harder to reason about (two overlapping deploys, ambiguous revision).

**Recommendation.** Add a short, concrete runbook: exact commands, where the app
lives, which services to reload, how to read the logs, how to roll back to the
previous release, and how to confirm the deployed revision. Cross-link from
`AGENTS.md`.

**Verify.** Someone unfamiliar can follow it end-to-end on a staging box.

---

## Appendix — health snapshot (2026-10-10 ~16:30 UTC)

| Signal | Value |
|---|---|
| Load average | 0.00 / 0.00 / 0.00 |
| Memory | 405 MB total · ~200 MB used · 2 GB swap |
| Homepage TTFB | ~50 ms sequential · 20 concurrent in ~0.4 s |
| PHP-FPM | `ondemand`, `max_children=5`, OPcache on |
| Database | Supabase Postgres (ap-southeast-1); RTT ~2.5 ms |
| Pending migrations | none |
| Git | `main` == `origin/main` == `8bdc78f`, working tree clean |
| Test suite | 82/82 passing (SQLite) |
