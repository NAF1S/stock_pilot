<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\OrderStatusEvent;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reserve → Confirm inventory engine.
 *
 * - order arrives (pending/on-hold/processing)  → RESERVE   components locked
 * - order completed                             → CONFIRM   deducted from on-hand
 * - order cancelled / failed / trash            → RELEASE   reservation freed
 * - order refunded / returned / returned-with-charge → RETURN (restock; charge recorded)
 *
 * A component is either a raw ITEM or a sub-PRODUCT (multi-level BOM). Every
 * order line also consumes the sellable product's OWN stock. Every change
 * writes an append-only row to sp_item_movements; corrections are reversal
 * rows (reversal_of), never edits. Item and product rows are locked in
 * ascending id order inside one transaction to avoid deadlocks.
 */
class InventoryEngine
{
    /**
     * Event time for the order currently being processed. Null means "use the
     * current time" (manual admin actions and legacy payloads without an
     * envelope). Set per call in processOrderEvent(), reset afterwards.
     */
    private ?\Illuminate\Support\Carbon $eventTime = null;

    public function __construct(
        private BomExpander $expander,
        private LowStockNotifier $notifier,
        private SettingService $settings,
    ) {
    }

    /**
     * @param  array<string, mixed>  $orderData  the "order" object from the plugin payload
     */
    public function processOrderEvent(array $orderData, StoreConnection $store, WebhookEvent $event): array
    {
        $status = strtolower((string) ($orderData['status'] ?? 'processing'));
        $affectedItemIds = [];
        $affectedProductIds = [];

        // The plugin sends when the WooCommerce event actually happened. Use
        // that for every timeline write; only fall back to "now" when absent.
        $this->eventTime = $this->resolveEventTime($orderData);

        $result = DB::transaction(function () use ($orderData, $store, $event, $status, &$affectedItemIds, &$affectedProductIds) {
            $order = Order::where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('woocommerce_id', $orderData['id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                $order = $this->createOrder($orderData, $store);
            }

            // Woo fires woocommerce_new_order before line items attach, so the
            // first event can carry an empty items list. Later events (status
            // change, refund, reconcile) carry the full order — create any
            // order items that are still missing (idempotent by item id).
            $this->syncOrderItems($order, $orderData, $store);

            $previousStatus = $order->status;

            $this->updateOrderMeta($order, $orderData);
            $this->recordStatusEvent($order, $previousStatus, $status, $orderData, $event);

            $action = $this->resolveAction($order->reservation_state, $status, $store->organization_id, $order);

            if ($action && $action !== 'noop') {
                [$affectedItemIds, $affectedProductIds] = $this->runAction($action, $order, $orderData);
                $order->refresh();
            }

            // ensureComponents() owns sync_status (processed/unmapped/error);
            // confirm() may override it with 'mismatch'. Only promote a brand-new
            // order (still 'pending' because its event was a noop) to processed.
            if ($order->sync_status === 'pending') {
                $order->sync_status = 'processed';
            }

            $order->last_webhook_event_id = $event->event_id;
            $order->save();

            return [
                'order_id' => $order->id,
                'reservation_state' => $order->reservation_state,
                'action' => $action ?? 'noop',
                'affected_item_ids' => $affectedItemIds,
                'affected_product_ids' => $affectedProductIds,
            ];
        });

        $this->eventTime = null;

        if (! empty($affectedItemIds)) {
            $this->notifier->evaluateForItems($affectedItemIds, $store->organization_id);
        }

        if (! empty($affectedProductIds)) {
            $this->notifier->evaluateForProducts($affectedProductIds, $store->organization_id);
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Order creation / metadata
    // ------------------------------------------------------------------

    private function createOrder(array $data, StoreConnection $store): Order
    {
        $orgId = $store->organization_id;

        $order = Order::create([
            'organization_id' => $orgId,
            'store_id' => $store->id,
            'woocommerce_id' => $data['id'],
            'woocommerce_number' => $data['number'] ?? (string) $data['id'],
            'status' => strtolower((string) ($data['status'] ?? 'pending')),
            'customer_name' => $data['customer']['name'] ?? null,
            'customer_email' => $data['customer']['email'] ?? null,
            'currency' => $data['currency'] ?? 'BDT',
            'order_created_at' => $this->parseDate($data['date_created'] ?? null),
            'order_updated_at' => $this->parseDate($data['date_modified'] ?? null),
            'payload' => $data,
            'sync_status' => 'pending',
        ]);

        return $order;
    }

    /**
     * Create any order items in the payload that the order doesn't already
     * have, matched by WooCommerce item id. Idempotent — safe to run on every
     * event, and it backfills orders whose first event carried no line items.
     */
    private function syncOrderItems(Order $order, array $data, StoreConnection $store): void
    {
        $existing = $order->items()
            ->pluck('woocommerce_item_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach ((array) ($data['items'] ?? []) as $index => $item) {
            $wooItemId = (int) ($item['id'] ?? 0);

            if ($wooItemId && in_array($wooItemId, $existing, true)) {
                continue;
            }

            // A payload item with no stable Woo id must not duplicate an
            // existing order line for the same product.
            if (! $wooItemId && $order->items()->where('product_name', $item['name'] ?? '')->exists()) {
                continue;
            }

            $product = $this->matchProduct($store->organization_id, $store->id, $item);

            OrderItem::create([
                'order_id' => $order->id,
                'woocommerce_item_id' => $item['id'] ?? $index,
                'woocommerce_product_id' => $item['product_id'] ?? null,
                'product_id' => $product?->id,
                'product_name' => $item['name'] ?? 'Unnamed',
                'sku' => $item['sku'] ?? null,
                'quantity' => (float) ($item['quantity'] ?? 0),
                'price' => (float) ($item['price'] ?? 0),
                'line_total' => (float) ($item['line_total'] ?? (($item['price'] ?? 0) * ($item['quantity'] ?? 0))),
                'sort' => $index,
            ]);
        }
    }

    private function updateOrderMeta(Order $order, array $data): void
    {
        $totals = $data['totals'] ?? $data;

        $order->status = strtolower((string) ($data['status'] ?? $order->status));
        $order->customer_name = $data['customer']['name'] ?? $order->customer_name;
        $order->customer_email = $data['customer']['email'] ?? $order->customer_email;
        $order->currency = $data['currency'] ?? $order->currency;
        $order->subtotal = (float) ($totals['subtotal'] ?? $order->subtotal);
        $order->discount_total = (float) ($totals['discount_total'] ?? 0);
        $order->shipping_total = (float) ($totals['shipping_total'] ?? 0);
        $order->tax_total = (float) ($totals['tax_total'] ?? 0);
        $order->total = (float) ($totals['total'] ?? $order->total);
        $order->refund_total = (float) ($totals['refund_total'] ?? $order->refund_total);
        $order->return_charge = (float) ($data['return_charge'] ?? $order->return_charge);
        $order->order_updated_at = $this->parseDate($data['date_modified'] ?? null) ?? $order->order_updated_at;
        $order->payload = $data;
    }

    private function recordStatusEvent(Order $order, string $statusFrom, string $statusTo, array $data, WebhookEvent $event): void
    {
        if (strtolower($statusFrom) === strtolower($statusTo)) {
            return;
        }

        OrderStatusEvent::create([
            'order_id' => $order->id,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'webhook_event_id' => $event->event_id,
            'payload' => $data,
            'occurred_at' => $this->eventTimeOrNow(),
        ]);
    }

    private function matchProduct(int $organizationId, ?int $storeId, array $item): ?Product
    {
        $candidates = collect();

        $wooId = (int) ($item['product_id'] ?? 0);
        $sku = is_string($item['sku'] ?? null) ? trim($item['sku']) : '';
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';

        // 1) Woo product id — store-scoped first, then org-wide (a product may
        //    have been created manually with no store link).
        if ($wooId) {
            $byStore = Product::where('organization_id', $organizationId)
                ->where('store_id', $storeId)
                ->where('woo_product_id', $wooId)
                ->where('is_active', true)
                ->get();

            $candidates = $candidates->merge($byStore);

            if ($byStore->isEmpty()) {
                $candidates = $candidates->merge(
                    Product::where('organization_id', $organizationId)
                        ->where('woo_product_id', $wooId)
                        ->where('is_active', true)
                        ->get()
                );
            }
        }

        // 2) SKU (trimmed, case-insensitive) — useful for products that do have SKUs.
        if ($sku !== '') {
            $candidates = $candidates->merge(
                Product::where('organization_id', $organizationId)
                    ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower($sku)])
                    ->where('is_active', true)
                    ->get()
            );
        }

        // 3) Exact product name — synced names are accurate, and stores without
        //    SKUs rely on this to map order lines.
        if ($name !== '') {
            $candidates = $candidates->merge(
                Product::where('organization_id', $organizationId)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                    ->where('is_active', true)
                    ->get()
            );
        }

        $candidates = $candidates->unique('id');

        if ($candidates->isEmpty()) {
            return null;
        }

        // Prefer the row that already has a recipe — that is the one the owner
        // configured; a duplicate synced row without a recipe loses.
        return $candidates->first(fn (Product $p) => $p->bomLines()->exists())
            ?? $candidates->first();
    }

    // ------------------------------------------------------------------
    // State machine
    // ------------------------------------------------------------------

    private function statusMap(int $organizationId): array
    {
        return (array) ($this->settings->get($organizationId, 'status_map') ?? [
            // "confirmed" is a custom status common in order-status plugins.
            'reserve' => ['pending', 'pending-payment', 'on-hold', 'processing', 'confirmed'],
            'confirm' => ['completed'],
            'release' => ['cancelled', 'failed', 'trash'],
            'return' => ['refunded', 'returned', 'returned-with-charge'],
        ]);
    }

    private function resolveAction(string $state, string $status, int $organizationId, Order $order): ?string
    {
        $incoming = null;

        foreach ($this->statusMap($organizationId) as $action => $statuses) {
            if (in_array($status, (array) $statuses, true)) {
                $incoming = $action;
                break;
            }
        }

        if (! $incoming) {
            return null; // unknown status — noop
        }

        // An order with zero components has done no work yet, so it is retryable
        // ('none') even if a previous event stamped 'reserved'/'confirmed' with
        // nothing to show for it. This self-heals the silent no-op bug.
        if (in_array($incoming, ['reserve', 'confirm'], true)
            && ! $order->components()->exists()) {
            $state = 'none';
        }

        return match ([$state, $incoming]) {
            ['none', 'reserve'] => 'reserve',
            ['none', 'confirm'] => 'confirm',                 // completed before processing
            ['none', 'return'], ['none', 'release'] => 'noop',
            ['reserved', 'confirm'] => 'confirm',
            ['reserved', 'release'] => 'release',
            ['reserved', 'return'] => 'release',              // never shipped — free reservation
            // Idempotent catch-up: re-running reserve only reserves NEW pending
            // components (e.g. a recipe added after the first event).
            ['reserved', 'reserve'] => 'reserve',
            ['confirmed', 'confirm'] => 'noop',               // duplicate completed
            ['confirmed', 'release'] => 'return',             // completed then cancelled — restock
            ['confirmed', 'return'] => 'return',
            ['partial', 'confirm'] => 'confirm',
            ['partial', 'release'] => 'release',
            ['partial', 'return'] => 'return',
            // Idempotent catch-up: re-running reserve tops under-covered (partial)
            // components up to their full demand without double-reserving.
            ['partial', 'reserve'] => 'reserve',
            ['released', 'confirm'] => 'confirm',
            default => 'noop',
        };
    }

    private function runAction(string $action, Order $order, array $orderData): array
    {
        return match ($action) {
            'reserve' => $this->reserve($order),
            'confirm' => $this->confirm($order),
            'release' => $this->release($order),
            'return' => $this->restock($order, $orderData),
            default => [[], []],
        };
    }

    // ------------------------------------------------------------------
    // Manual Admin Intervention Actions (Support / Correction Features)
    // ------------------------------------------------------------------

    public function manualReserve(Order $order): array
    {
        $this->eventTime = null; // admin action happens now

        $affectedItemIds = [];
        $affectedProductIds = [];

        $result = DB::transaction(function () use ($order, &$affectedItemIds, &$affectedProductIds) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->reservation_state === 'confirmed') {
                abort(422, "Order #{$lockedOrder->woocommerce_number} is already confirmed. Stock has already been deducted.");
            }

            $previousState = $lockedOrder->reservation_state;
            [$affectedItemIds, $affectedProductIds] = $this->reserve($lockedOrder);
            $lockedOrder->refresh();

            OrderStatusEvent::create([
                'order_id' => $lockedOrder->id,
                'status_from' => $previousState,
                'status_to' => 'manual:reserve',
                'payload' => [
                    'action' => 'reserve',
                    'user_id' => auth()->id(),
                    'user_name' => auth()->user()?->name,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'order_id' => $lockedOrder->id,
                'reservation_state' => $lockedOrder->reservation_state,
                'action' => 'reserve',
                'affected_item_ids' => $affectedItemIds,
                'affected_product_ids' => $affectedProductIds,
            ];
        });

        if (! empty($affectedItemIds)) {
            $this->notifier->evaluateForItems($affectedItemIds, $order->organization_id);
        }
        if (! empty($affectedProductIds)) {
            $this->notifier->evaluateForProducts($affectedProductIds, $order->organization_id);
        }

        return $result;
    }

    public function manualConfirm(Order $order): array
    {
        $this->eventTime = null; // admin action happens now

        $affectedItemIds = [];
        $affectedProductIds = [];

        $result = DB::transaction(function () use ($order, &$affectedItemIds, &$affectedProductIds) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $previousState = $lockedOrder->reservation_state;

            [$affectedItemIds, $affectedProductIds] = $this->confirm($lockedOrder);
            $lockedOrder->refresh();

            OrderStatusEvent::create([
                'order_id' => $lockedOrder->id,
                'status_from' => $previousState,
                'status_to' => 'manual:confirm',
                'payload' => [
                    'action' => 'confirm',
                    'user_id' => auth()->id(),
                    'user_name' => auth()->user()?->name,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'order_id' => $lockedOrder->id,
                'reservation_state' => $lockedOrder->reservation_state,
                'action' => 'confirm',
                'affected_item_ids' => $affectedItemIds,
                'affected_product_ids' => $affectedProductIds,
            ];
        });

        if (! empty($affectedItemIds)) {
            $this->notifier->evaluateForItems($affectedItemIds, $order->organization_id);
        }
        if (! empty($affectedProductIds)) {
            $this->notifier->evaluateForProducts($affectedProductIds, $order->organization_id);
        }

        return $result;
    }

    public function manualRelease(Order $order): array
    {
        $this->eventTime = null; // admin action happens now

        $affectedItemIds = [];
        $affectedProductIds = [];

        $result = DB::transaction(function () use ($order, &$affectedItemIds, &$affectedProductIds) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $previousState = $lockedOrder->reservation_state;

            if ($lockedOrder->reservation_state === 'confirmed') {
                $payload = is_array($lockedOrder->payload) ? $lockedOrder->payload : [];
                [$affectedItemIds, $affectedProductIds] = $this->restock($lockedOrder, $payload);
                $lockedOrder->reservation_state = 'released';
                $lockedOrder->released_at = $this->eventTimeOrNow();
                $lockedOrder->save();
            } else {
                [$affectedItemIds, $affectedProductIds] = $this->release($lockedOrder);
            }
            $lockedOrder->refresh();

            OrderStatusEvent::create([
                'order_id' => $lockedOrder->id,
                'status_from' => $previousState,
                'status_to' => 'manual:release',
                'payload' => [
                    'action' => 'release',
                    'user_id' => auth()->id(),
                    'user_name' => auth()->user()?->name,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'order_id' => $lockedOrder->id,
                'reservation_state' => $lockedOrder->reservation_state,
                'action' => 'release',
                'affected_item_ids' => $affectedItemIds,
                'affected_product_ids' => $affectedProductIds,
            ];
        });

        if (! empty($affectedItemIds)) {
            $this->notifier->evaluateForItems($affectedItemIds, $order->organization_id);
        }
        if (! empty($affectedProductIds)) {
            $this->notifier->evaluateForProducts($affectedProductIds, $order->organization_id);
        }

        return $result;
    }

    /**
     * Force confirm all components of an order without deducting current on-hand stock.
     * Clears 'mismatch' to 'processed' (ok), sets reservation_state to 'confirmed',
     * releases any pending reservation, marks all components confirmed_quantity = total_quantity,
     * and logs append-only ledger rows with qty_delta = 0 so history is registered.
     */
    public function manualConfirmWithoutDeduction(Order $order): array
    {
        $this->eventTime = null; // admin action happens now

        $affectedItemIds = [];
        $affectedProductIds = [];

        $result = DB::transaction(function () use ($order, &$affectedItemIds, &$affectedProductIds) {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $previousState = $lockedOrder->reservation_state;

            $this->ensureComponents($lockedOrder);
            $components = $this->componentsLocked($lockedOrder);

            if ($components->isNotEmpty()) {
                $entities = $this->lockEntities($components);

                foreach ($components as $component) {
                    $entity = $this->entityFor($component, $entities);
                    if (! $entity) {
                        continue;
                    }

                    $target = (float) $component->total_quantity;
                    $previouslyReserved = (float) $component->reserved_quantity;

                    // If anything was reserved, release it so quantity_reserved is freed
                    if ($previouslyReserved > 0) {
                        $entity->quantity_reserved = max(0.0, round($entity->quantity_reserved - $previouslyReserved, 4));
                        $entity->save();
                    }

                    // Update component to fully confirmed
                    $component->confirmed_quantity = $target;
                    $component->reserved_quantity = 0;
                    $component->confirmed_at = $this->eventTimeOrNow();
                    $component->status = 'confirmed';
                    $component->save();

                    // Append-only ledger movement: qty_delta = 0 (no deduction from current stock),
                    // reserved_delta = -$previouslyReserved, on_hand_after = entity on_hand (unchanged)
                    $this->ledger($lockedOrder, $entity, $component, 'confirm', 0, -$previouslyReserved);

                    $this->collectEntityId($entity, $affectedItemIds, $affectedProductIds);
                }
            }

            $lockedOrder->reservation_state = 'confirmed';
            $lockedOrder->confirmed_at = $this->eventTimeOrNow();
            // Clear mismatch to processed (shows ok in portal)
            $lockedOrder->sync_status = 'processed';
            $lockedOrder->save();

            OrderStatusEvent::create([
                'order_id' => $lockedOrder->id,
                'status_from' => $previousState,
                'status_to' => 'manual:confirm_no_deduct',
                'payload' => [
                    'action' => 'confirm_without_deduction',
                    'user_id' => auth()->id(),
                    'user_name' => auth()->user()?->name,
                    'reason' => 'Force confirmed without stock deduction (cleared mismatch)',
                ],
                'occurred_at' => now(),
            ]);

            return [
                'order_id' => $lockedOrder->id,
                'reservation_state' => $lockedOrder->reservation_state,
                'sync_status' => $lockedOrder->sync_status,
                'action' => 'confirm_no_deduct',
                'affected_item_ids' => $affectedItemIds,
                'affected_product_ids' => $affectedProductIds,
            ];
        });

        return $result;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    private function reserve(Order $order): array
    {
        $this->ensureComponents($order);
        $components = $this->componentsLocked($order);

        if ($components->isEmpty()) {
            // Nothing mapped — don't pretend we reserved anything.
            $order->sync_status = 'unmapped';
            $order->save();

            return [[], []];
        }

        $entities = $this->lockEntities($components);
        $itemIds = [];
        $productIds = [];

        foreach ($components as $component) {
            // Process pending, under-covered 'partial', AND previously 'released'
            // components. Fully-reserved components are skipped (idempotent).
            if (! in_array($component->status, ['pending', 'partial', 'released'], true)) {
                continue;
            }

            $entity = $this->entityFor($component, $entities);

            if (! $entity) {
                continue;
            }

            $wanted = (float) $component->total_quantity;

            // The ledger is the source of truth for what THIS order has already
            // reserved for the entity, taking into account both reserve and release.
            $already = (float) ItemMovement::where('ref_type', 'order')
                ->where('ref_id', $order->id)
                ->whereIn('movement_type', ['reserve', 'release'])
                ->where($entity instanceof Item ? 'item_id' : 'product_id', $entity->id)
                ->sum('reserved_delta');

            $toReserve = round($wanted - $already, 4);

            if ($toReserve <= 0) {
                continue;
            }

            // Always reserve the FULL wanted quantity so the reserved column shows
            // true demand. When stock is short, available goes negative — that
            // shortfall is the signal the owner needs. The order is marked
            // 'partial' when any component is under-covered.
            $available = round($entity->quantity_on_hand - $entity->quantity_reserved, 4);

            $entity->quantity_reserved = round($entity->quantity_reserved + $toReserve, 4);
            $entity->save();

            $component->reserved_quantity = round($already + $toReserve, 4);
            $component->reserved_at = $this->eventTimeOrNow();
            $component->status = $wanted > $available ? 'partial' : 'reserved';
            $component->save();

            $this->ledger($order, $entity, $component, 'reserve', 0, $toReserve);

            $this->collectEntityId($entity, $itemIds, $productIds);
        }

        $hasPartial = $order->components()
            ->whereIn('status', ['partial'])
            ->exists();

        $order->reservation_state = $hasPartial ? 'partial' : 'reserved';
        $order->reserved_at = $this->eventTimeOrNow();
        $order->save();

        return [$itemIds, $productIds];
    }

    private function confirm(Order $order): array
    {
        $this->ensureComponents($order);
        $components = $this->componentsLocked($order);

        if ($components->isEmpty()) {
            $order->sync_status = 'unmapped';
            $order->save();

            return [[], []];
        }

        $entities = $this->lockEntities($components);
        $itemIds = [];
        $productIds = [];
        $allowNegative = (bool) $this->settings->get($order->organization_id, 'allow_negative_stock', false);

        foreach ($components as $component) {
            if (! in_array($component->status, ['pending', 'reserved', 'partial', 'released'], true)) {
                continue;
            }

            $entity = $this->entityFor($component, $entities);

            if (! $entity) {
                continue;
            }

            // Idempotency: the ledger is the source of truth for whether THIS
            // order already confirmed THIS entity. A re-processed webhook, a
            // force re-sync with a fresh event id, or a stale duplicate event
            // must never deduct the same goods twice — only the component cache
            // (or a recipe/mapping change) makes them look unconfirmed again.
            // Same pattern as reserve(), which consults the ledger too.
            $alreadyConfirmed = ItemMovement::where('ref_type', 'order')
                ->where('ref_id', $order->id)
                ->where('movement_type', 'confirm')
                ->where($entity instanceof Item ? 'item_id' : 'product_id', $entity->id)
                ->exists();

            if ($alreadyConfirmed) {
                continue;
            }

            // pending or released = never reserved or freed reservation; confirm the full recipe.
            $toConfirm = in_array($component->status, ['pending', 'released'], true)
                ? (float) $component->total_quantity
                : (float) $component->reserved_quantity;
            $previouslyReserved = (float) $component->reserved_quantity;

            if ($toConfirm <= 0) {
                continue;
            }

            $newOnHand = round($entity->quantity_on_hand - $toConfirm, 4);

            if ($newOnHand < 0 && ! $allowNegative) {
                // Clamp to what we actually have; flag the order as a mismatch.
                $toConfirm = max(0.0, $entity->quantity_on_hand);
                $newOnHand = 0.0;
                $order->sync_status = 'mismatch';
            }

            $entity->quantity_on_hand = round($newOnHand, 4);
            $entity->quantity_reserved = round($entity->quantity_reserved - $previouslyReserved, 4);
            $entity->save();

            $component->confirmed_quantity = round($component->confirmed_quantity + $toConfirm, 4);
            $component->reserved_quantity = 0;
            $component->confirmed_at = $this->eventTimeOrNow();
            $component->status = 'confirmed';
            $component->save();

            $this->ledger($order, $entity, $component, 'confirm', -$toConfirm, -$previouslyReserved);

            $this->collectEntityId($entity, $itemIds, $productIds);
        }

        $order->reservation_state = 'confirmed';
        $order->confirmed_at = $this->eventTimeOrNow();
        $order->save();

        return [$itemIds, $productIds];
    }

    private function release(Order $order): array
    {
        $components = $this->componentsLocked($order);
        $entities = $this->lockEntities($components);
        $itemIds = [];
        $productIds = [];

        foreach ($components as $component) {
            if ($component->reserved_quantity <= 0) {
                continue;
            }

            $entity = $this->entityFor($component, $entities);

            if (! $entity) {
                continue;
            }

            $released = (float) $component->reserved_quantity;

            $entity->quantity_reserved = round($entity->quantity_reserved - $released, 4);
            $entity->save();

            $component->reserved_quantity = 0;
            $component->released_at = $this->eventTimeOrNow();
            $component->status = 'released';
            $component->save();

            $this->ledger($order, $entity, $component, 'release', 0, -$released);

            $this->collectEntityId($entity, $itemIds, $productIds);
        }

        $order->reservation_state = 'released';
        $order->released_at = $this->eventTimeOrNow();
        $order->save();

        return [$itemIds, $productIds];
    }

    private function restock(Order $order, array $orderData): array
    {
        $components = $this->componentsLocked($order);
        $itemIds = [];
        $productIds = [];

        if ($components->isEmpty()) {
            $order->reservation_state = 'released';
            $order->save();

            return [[], []];
        }

        $entities = $this->lockEntities($components);
        $fractions = $this->returnFractions($orderData, $order);
        // Prefer the refund's own timestamp when the payload carries one.
        $returnedAt = $this->resolveReturnTime($orderData) ?? $this->eventTimeOrNow();
        $anyReturned = false;

        foreach ($components as $component) {
            $confirmed = (float) $component->confirmed_quantity;

            if ($confirmed <= 0) {
                continue;
            }

            $fraction = $fractions[$component->order_item_id] ?? 1.0;
            $returnAmt = round($confirmed * $fraction, 4);

            if ($returnAmt <= 0) {
                continue;
            }

            $entity = $this->entityFor($component, $entities);

            if (! $entity) {
                continue;
            }

            $entity->quantity_on_hand = round($entity->quantity_on_hand + $returnAmt, 4);
            $entity->save();

            $component->returned_quantity = round($component->returned_quantity + $returnAmt, 4);
            $component->confirmed_quantity = round($component->confirmed_quantity - $returnAmt, 4);
            $component->returned_at = $returnedAt;
            $component->status = $component->confirmed_quantity <= 0 && $component->reserved_quantity <= 0
                ? 'returned'
                : 'partial';
            $component->save();

            $this->ledger($order, $entity, $component, 'return', $returnAmt, 0, $returnedAt);

            $anyReturned = true;
            $this->collectEntityId($entity, $itemIds, $productIds);
        }

        $fullyReturned = $order->components()->lockForUpdate()->get()
            ->every(fn (OrderItemComponent $c) => in_array($c->status, ['returned', 'pending', 'released'], true));

        $order->reservation_state = $anyReturned && $fullyReturned ? 'returned' : ($anyReturned ? 'partial' : 'released');
        $order->returned_at = $returnedAt;
        $order->save();

        return [$itemIds, $productIds];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Re-run the expander and insert any component rows that are still missing,
     * so a later fix (recipe added, product mapped) self-heals an order without
     * disturbing already-reserved rows. Owns the order's sync_status.
     *
     * @return list<int> unmapped order-item ids
     */
    private function ensureComponents(Order $order): array
    {
        // Re-match order items that didn't map at creation time (a product may
        // have been added since), so fixed SKUs self-heal on the next event.
        $this->remapUnmatchedOrderItems($order);

        $result = $this->expander->expandForOrder($order);
        $existing = $order->components()->get();

        foreach ($result['rows'] as $row) {
            // Normalize to ints (null -> 0) so the comparison is type-safe on
            // every DB driver. A strict !== between a string "30" and int 30
            // would otherwise fail the match and create a duplicate component
            // on every reprocess → double reservation.
            $match = fn (OrderItemComponent $c) => (int) $c->order_item_id === (int) $row['order_item_id']
                && (int) $c->item_id === (int) $row['item_id']
                && (int) $c->component_product_id === (int) $row['component_product_id'];

            if ($existing->first($match)) {
                continue;
            }

            OrderItemComponent::create($row + [
                'organization_id' => $order->organization_id,
                'order_id' => $order->id,
                'status' => 'pending',
            ]);
        }

        if ($result['cycle_error']) {
            $order->sync_status = 'error';
        } elseif (! empty($result['unmapped'])) {
            $order->sync_status = 'unmapped';
        } elseif (in_array($order->sync_status, ['pending', 'unmapped'], true)) {
            $order->sync_status = 'processed';
        }

        $order->save();

        return $result['unmapped'];
    }

    /**
     * Point unmapped order items at a product that now matches them — by the
     * Woo product id they carried, then by exact name (the reliable key for
     * stores without SKUs), then by SKU as a last resort.
     */
    private function remapUnmatchedOrderItems(Order $order): void
    {
        foreach ($order->items()->whereNull('product_id')->get() as $orderItem) {
            $product = null;

            if ($orderItem->woocommerce_product_id) {
                $product = Product::where('organization_id', $order->organization_id)
                    ->where('woo_product_id', $orderItem->woocommerce_product_id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();
            }

            if (! $product && is_string($orderItem->product_name) && trim($orderItem->product_name) !== '') {
                $product = Product::where('organization_id', $order->organization_id)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($orderItem->product_name))])
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();
            }

            if (! $product && is_string($orderItem->sku) && trim($orderItem->sku) !== '') {
                $product = Product::where('organization_id', $order->organization_id)
                    ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower(trim($orderItem->sku))])
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();
            }

            if (! $product) {
                continue;
            }

            $orderItem->product_id = $product->id;
            $orderItem->save();
        }
    }

    /** Lock component rows (order-scoped; the order row is already locked). */
    private function componentsLocked(Order $order): Collection
    {
        return $order->components()
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * Lock every touched stockable row — items and products — in ascending id
     * order, so concurrent order events across different tables cannot deadlock.
     *
     * @return array{item: Collection<int, Item>, product: Collection<int, Product>}
     */
    private function lockEntities(Collection $components): array
    {
        $itemIds = $components->pluck('item_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        $productIds = $components->pluck('component_product_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();

        $items = Item::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return ['item' => $items, 'product' => $products];
    }

    /**
     * @param  array{item: Collection<int, Item>, product: Collection<int, Product>}  $entities
     */
    private function entityFor(OrderItemComponent $component, array $entities): Item|Product|null
    {
        if ($component->item_id !== null) {
            return $entities['item']->get($component->item_id);
        }

        if ($component->component_product_id !== null) {
            return $entities['product']->get($component->component_product_id);
        }

        return null;
    }

    /**
     * @param  list<int>  $itemIds
     * @param  list<int>  $productIds
     */
    private function collectEntityId(Item|Product $entity, array &$itemIds, array &$productIds): void
    {
        if ($entity instanceof Item) {
            $itemIds[] = $entity->id;
        } else {
            $productIds[] = $entity->id;
        }
    }

    private function ledger(
        Order $order,
        Item|Product $entity,
        OrderItemComponent $component,
        string $type,
        float $qtyDelta,
        float $reservedDelta,
        ?\Illuminate\Support\Carbon $occurredAt = null,
    ): void {
        ItemMovement::create([
            'organization_id' => $order->organization_id,
            'item_id' => $entity instanceof Item ? $entity->id : null,
            'product_id' => $entity instanceof Product ? $entity->id : null,
            'movement_type' => $type,
            'qty_delta' => $qtyDelta,
            'reserved_delta' => $reservedDelta,
            'on_hand_after' => $entity->quantity_on_hand,
            'reserved_after' => $entity->quantity_reserved,
            'ref_type' => 'order',
            'ref_id' => $order->id,
            'reversal_of' => $this->originalMovementId($order, $entity, $type),
            'cost_price_at_movement' => $entity instanceof Item ? $entity->cost_price : ($entity->cost ?? 0),
            'reason' => sprintf('%s — order #%s (%s %s)', $type, $order->woocommerce_number, $entity->name, $entity instanceof Item ? 'item' : 'product'),
            // Business event time — never the webhook delivery/processing time.
            'occurred_at' => $occurredAt ?? $this->eventTimeOrNow(),
        ]);
    }

    /** For release/return, point the reversal at the original reserve/confirm row. */
    private function originalMovementId(Order $order, Item|Product $entity, string $type): ?int
    {
        $originalType = $type === 'release' ? 'reserve' : ($type === 'return' ? 'confirm' : null);

        if (! $originalType) {
            return null;
        }

        $query = ItemMovement::where('ref_type', 'order')
            ->where('ref_id', $order->id)
            ->where('movement_type', $originalType);

        if ($entity instanceof Item) {
            $query->where('item_id', $entity->id);
        } else {
            $query->where('product_id', $entity->id);
        }

        return $query->value('id');
    }

    private function returnFractions(array $orderData, Order $order): array
    {
        $fractions = [];

        foreach ((array) ($orderData['refunds'] ?? []) as $refund) {
            foreach ((array) ($refund['items'] ?? []) as $line) {
                $wooItemId = $line['item_id'] ?? null;
                $qty = (float) ($line['quantity'] ?? 0);

                if (! $wooItemId || $qty <= 0) {
                    continue;
                }

                $orderItem = $order->items()->where('woocommerce_item_id', $wooItemId)->first();

                if (! $orderItem || $orderItem->quantity <= 0) {
                    continue;
                }

                $fraction = $qty / $orderItem->quantity;
                $fractions[$orderItem->id] = max($fractions[$orderItem->id] ?? 0, $fraction);
            }
        }

        return $fractions;
    }

    /**
     * Resolve the business event time from the plugin envelope, falling back to
     * the order's own WooCommerce timestamps. Returns null when nothing valid
     * exists (legacy payloads), in which case callers use the current time.
     */
    private function resolveEventTime(array $orderData): ?\Illuminate\Support\Carbon
    {
        $event = is_array($orderData['event'] ?? null) ? $orderData['event'] : [];

        $candidates = [
            $event['occurred_at'] ?? null,
            $orderData['date_modified'] ?? null,
            $orderData['date_created'] ?? null,
        ];

        foreach ($candidates as $value) {
            $at = $this->parseClamped($value);

            if ($at) {
                return $at;
            }
        }

        return null;
    }

    /** Latest refund timestamp — refund events are the business time for returns. */
    private function resolveReturnTime(array $orderData): ?\Illuminate\Support\Carbon
    {
        $latest = null;

        foreach ((array) ($orderData['refunds'] ?? []) as $refund) {
            $at = $this->parseClamped($refund['date_created'] ?? null);

            if ($at && (! $latest || $at->greaterThan($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * Parse an ISO timestamp and reject impossible values, so a bad clock or a
     * corrupt payload can never poison the timeline.
     */
    private function parseClamped($value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $at = \Illuminate\Support\Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }

        $now = now();

        if ($at->greaterThan($now->copy()->addMinutes(5)) || $at->lessThan($now->copy()->subDays(400))) {
            return null;
        }

        return $at;
    }

    /** Event time for the current order, or now() for manual/legacy flows. */
    private function eventTimeOrNow(): \Illuminate\Support\Carbon
    {
        return $this->eventTime ? $this->eventTime->copy() : now();
    }

    private function parseDate(?string $value): ?\Illuminate\Support\Carbon
    {
        return $value ? \Illuminate\Support\Carbon::parse($value) : null;
    }
}
