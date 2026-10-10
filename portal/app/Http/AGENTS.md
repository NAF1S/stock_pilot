# portal/app/Http — Controllers & middleware

API controllers live in `Api/`. See [../AGENTS.md](../AGENTS.md) for layering and
[../../routes/AGENTS.md](../../routes/AGENTS.md) for the full URL map.

## Controller conventions

- Extend `App\Http\Controllers\Controller`; every action returns `Illuminate\Http\JsonResponse`.
- Validation is inline (`$request->validate([...])`) — this codebase has no FormRequests. Reference real
  table names in rules, e.g. `'category_id' => 'nullable|exists:sp_categories,id'`.
- Tenant scoping is explicit and manual:

  ```php
  $orgId = $request->user()->organization_id;
  Item::where('organization_id', $orgId)->...
  ```

  For route-model-bound resources call `$this->assertSameOrg($request, $item)` first. It **aborts 404**,
  not 403, so another tenant's record is indistinguishable from a missing one.
- Response shapes are consistent: `['item' => ...]`, `['items' => ...]`, `['ok' => true]`. List endpoints
  return the Laravel paginator JSON directly (`data`, `current_page`, `last_page`, `total`) after shaping
  rows with `$page->getCollection()->transform(fn ($m) => $this->payload($m))`.
- Each controller has a private `payload(Model $m): array` that produces the snake_case keys the SPA reads.
  When you add a column that the UI needs, update the cast, `#[Fillable]`, and this payload together.

## Status codes

`201` created · `422` validation or business-rule violation (`abort(422, '...')`) ·
`404` missing or cross-tenant · `409` duplicate webhook (unique-constraint violation) ·
`500` webhook processing failure (the event row is deleted so the plugin can retry).

## Middleware

| Alias / class | Purpose |
|---|---|
| `store.key` → [AuthenticateStoreKey](Middleware/AuthenticateStoreKey.php) | Plugin auth: `X-Api-Key` is looked up by SHA-256 hash, then a `base64(HMAC-SHA256(secret, "{timestamp}:{nonce}:{event_id}:{body}"))` signature is compared with `hash_equals`; rejects inactive/revoked stores and timestamps outside ±300s; exposes the store via `$request->attributes->get('store')`. |
| `role:...` → [EnsureRole](Middleware/EnsureRole.php) | Aborts 401 when unauthenticated, 403 when the role is not in the list. |
| [AutoMigrate](Middleware/AutoMigrate.php) | Prepended to `web` + `api`; runs pending migrations before the session table is read. |

## WebhookController notes

- Deduplication relies on the unique constraints on `sp_webhook_events` (`store_id+event_id`,
  `store_id+nonce`): catching `UniqueConstraintViolationException` is what produces the `409` response.
- `dispatch()` chooses inline vs queued processing from `config('stockpilot.webhook_mode')`. In sync mode a
  failure deletes the event and aborts 500 so the plugin's retry re-delivers it cleanly.
- Do not add session/encryption middleware to the `api` group (see [../AGENTS.md](../AGENTS.md)) — Sanctum
  already injects one stateful session stack and duplicates cause 401s after login.
