# stockpilot-for-woocommerce — WordPress plugin

A **read-only** WooCommerce → StockPilot bridge. PHP 7.4+ (runs on 8.x), WordPress 6.0+,
HPOS-compatible. It only *reads* WooCommerce data and forwards it — it never calls
`update_status()`, never touches WooCommerce stock functions, and never writes post meta.

## Layout

| File | Role |
|---|---|
| [stockpilot-for-woocommerce.php](stockpilot-for-woocommerce.php) | Plugin header, constants, `spw_boot()` on `plugins_loaded`, activation/deactivation hooks |
| [includes/class-spw-plugin.php](includes/class-spw-plugin.php) | Singleton wiring, table DDL via `dbDelta`, cron schedules, queue/log table names |
| [includes/class-spw-capture.php](includes/class-spw-capture.php) | `add_action` hook watchers that enqueue events |
| [includes/class-spw-payload.php](includes/class-spw-payload.php) | Builds order/product payloads and deterministic `event_id`s |
| [includes/class-spw-queue.php](includes/class-spw-queue.php) | Outbound queue: dedup, claim/lock, backoff, dead-letter, sync log |
| [includes/class-spw-sender.php](includes/class-spw-sender.php) | HTTPS POST with HMAC signing, plus `test_connection()` |
| [includes/class-spw-settings.php](includes/class-spw-settings.php) | `spw_settings` option, secret encryption, `is_connected()` |
| [includes/class-spw-encryption.php](includes/class-spw-encryption.php) | AES-256-CBC helper keyed from `AUTH_KEY` / `AUTH_SALT` |
| [includes/class-spw-cron.php](includes/class-spw-cron.php) | `spw_process_queue` (every minute) and hourly `spw_reconcile` |
| [admin/class-spw-admin.php](admin/class-spw-admin.php) + [admin/views/settings.php](admin/views/settings.php) | Settings screen, admin notices, status summary |
| [uninstall.php](uninstall.php) | Drops the two custom tables (deactivation keeps them) |

## Conventions

- Classes are `SPW_*` singletons (`instance()` + private constructor); functions, options and hooks are
  `spw_`-prefixed; text domain `stockpilot-wc`.
- Every file starts with `defined( 'ABSPATH' ) || exit;`.
- WordPress style: tabs for indentation, `array()` syntax, `$wpdb->prepare()` for every query with input —
  match the surrounding file rather than reformatting it.
- Third-party data is escaped on output in the admin views (`esc_html`, `esc_attr`, `esc_url`).

## Stored data

- Tables: `{prefix}spw_outbound_queue` (`payload_id`, `event_id`, `event_type`, `entity_id`, `payload`,
  `nonce`, `attempts`, `max_attempts`, `next_attempt_at`, `locked_until`, `status`, `last_error`) and
  `{prefix}spw_sync_log` (unique `event_id`, `payload_hash`).
- Options: `spw_settings` (`portal_url`, `api_key`, `api_secret` ciphertext prefixed `spw1:`, `disabled`),
  `spw_last_order_reconcile`, `spw_last_product_reconcile`.

## Delivery pipeline

`SPW_Capture` hooks → `SPW_Payload::enqueue_order|enqueue_product` → `SPW_Queue::enqueue`
(skips when the `event_id` is already in the sync log or already pending) → WP-Cron `spw_process_queue`
→ `SPW_Queue::claim(15)` (rows flip to `sending` with a 120s lock; expired locks are reclaimed)
→ `SPW_Sender::send()` → status handling:

| Portal response | Action |
|---|---|
| `200` / `409` | mark `done` and write the sync-log row (409 = already processed; drop cleanly) |
| `422` | mark `dead` (payload will never be accepted) |
| `401` / `403` | set `disabled=yes`, mark `dead`, notify the admin (revoked key) |
| anything else | retry with `min(600, 15 · 2^(attempts-1)) + jitter`; `dead` after 12 attempts |

Reconciliation (`spw_reconcile`, hourly) re-enqueues orders/products modified since the last run as a
safety net for missed hooks; `SPW_Cron::sync_orders_now($force)` powers the manual "sync now" button.

## Security

- HMAC-SHA256 over `timestamp:nonce:event_id:body`, base64-encoded, sent as `X-Api-Key`, `X-Timestamp`,
  `X-Nonce`, `X-Event-Id`, `X-Signature`. The portal enforces a ±300s window and rejects reused nonces.
- HTTPS is required unless `SPW_ALLOW_HTTP` is defined (local testing only).
- The API secret is encrypted at rest with AES-256-CBC via `AUTH_KEY`/`AUTH_SALT`; never log or store it plain.

## Changing behaviour

- An order payload with an empty `items` array is skipped **on purpose** — Woo fires
  `woocommerce_new_order` before line items attach, and a payload without items would create an empty order
  row in the portal. Don't "fix" this.
- A new event type means: payload builder + enqueue call, extend `SPW_Sender::send()`'s endpoint mapping,
  and add portal-side ingest/processing (see [../portal/app/Http/AGENTS.md](../portal/app/Http/AGENTS.md)).
- Keep the payload contract in sync with `InventoryEngine` / `ProductSyncProcessor` in the portal, and read
  [README.md](README.md) for the user-facing description of what is sent.
- Validate with `bash scripts/build-plugin-upload.sh` — it runs `php -l` on every file before zipping.
