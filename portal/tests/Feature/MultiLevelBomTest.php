<?php

use App\Models\BomLine;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AssetValuation;
use App\Services\InventoryEngine;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Org with:
 *   Belt       (recipe: Leather + Buckle)      on_hand 10
 *   Money Bag  (recipe: Card + Box)            on_hand 8
 *   Belt Combo (recipe: Belt + Money Bag + Poly) on_hand 5
 */
function comboFixture(): array
{
    $org = Organization::create(['name' => 'Combo Org', 'low_stock_email' => 'alerts@test.dev']);
    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Store',
        'store_url' => 'https://shop.example.com',
        'api_key' => 'sp_key',
        'api_secret' => 'secretsecretsecretsecretsecretsecret',
    ]);

    $items = [];

    foreach (['Leather' => 20, 'Buckle' => 20, 'Card' => 50, 'Box' => 50, 'Poly' => 50] as $name => $qty) {
        $items[$name] = Item::create([
            'organization_id' => $org->id,
            'name' => $name,
            'unit' => 'pcs',
            'cost_price' => 10,
            'quantity_on_hand' => $qty,
            'low_stock_threshold' => 5,
        ]);

        ItemMovement::create([
            'organization_id' => $org->id,
            'item_id' => $items[$name]->id,
            'movement_type' => 'initial',
            'qty_delta' => $qty,
            'reserved_delta' => 0,
            'on_hand_after' => $qty,
            'reserved_after' => 0,
            'ref_type' => 'seed',
            'cost_price_at_movement' => 10,
            'reason' => 'initial',
        ]);
    }

    $belt = Product::create(['organization_id' => $org->id, 'name' => 'Belt', 'sku' => 'BELT', 'source' => 'manual', 'quantity_on_hand' => 10, 'cost' => 100]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $belt->id, 'item_id' => $items['Leather']->id, 'component_type' => 'item', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $belt->id, 'item_id' => $items['Buckle']->id, 'component_type' => 'item', 'quantity' => 1]);

    $moneyBag = Product::create(['organization_id' => $org->id, 'name' => 'Money Bag', 'sku' => 'MBAG', 'source' => 'manual', 'quantity_on_hand' => 8, 'cost' => 50]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $moneyBag->id, 'item_id' => $items['Card']->id, 'component_type' => 'item', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $moneyBag->id, 'item_id' => $items['Box']->id, 'component_type' => 'item', 'quantity' => 1]);

    $combo = Product::create(['organization_id' => $org->id, 'name' => 'Belt + Money Bag Combo', 'sku' => 'BELT-COMBO', 'source' => 'manual', 'quantity_on_hand' => 5, 'cost' => 200]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'component_product_id' => $belt->id, 'component_type' => 'product', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'component_product_id' => $moneyBag->id, 'component_type' => 'product', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'item_id' => $items['Poly']->id, 'component_type' => 'item', 'quantity' => 1]);

    return [$org, $store, $items, ['belt' => $belt, 'moneyBag' => $moneyBag, 'combo' => $combo]];
}

function comboOrder(int $id, string $sku, string $status, int $qty = 2, ?int $productId = null): array
{
    return [
        'id' => $id,
        'number' => (string) $id,
        'status' => $status,
        'date_created' => now()->toIso8601String(),
        'date_modified' => now()->toIso8601String(),
        'customer' => ['name' => 'Buyer', 'email' => 'buyer@dev'],
        'currency' => 'BDT',
        'totals' => ['subtotal' => 100, 'total' => 100],
        'items' => [
            ['id' => 1, 'product_id' => $productId, 'name' => $sku, 'sku' => $sku, 'quantity' => $qty, 'price' => 100, 'line_total' => 100 * $qty],
        ],
        'refunds' => [],
    ];
}

function comboEvent(array $orderData, Organization $org, StoreConnection $store, string $eventId): array
{
    $event = WebhookEvent::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_order_id' => $orderData['id'],
        'event_id' => $eventId,
        'nonce' => Str::random(32),
        'event_type' => 'order.sync',
        'wc_status' => $orderData['status'],
        'payload' => json_encode(['order' => $orderData]),
        'status' => 'received',
    ]);

    return [$orderData, $event];
}

test('a combo order reserves sub-product stock and every leaf item', function () {
    [$org, $store, $items, $products] = comboFixture();
    [$data, $event] = comboEvent(comboOrder(1, 'BELT-COMBO', 'processing', 2), $org, $store, 'e1');

    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect($result['action'])->toBe('reserve');
    expect($result['reservation_state'])->toBe('reserved');

    expect($products['combo']->fresh()->quantity_reserved)->toBe(2.0);    // own stock
    expect($products['belt']->fresh()->quantity_reserved)->toBe(2.0);     // sub-product
    expect($products['moneyBag']->fresh()->quantity_reserved)->toBe(2.0); // sub-product
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(2.0);     // belt's recipe
    expect($items['Buckle']->fresh()->quantity_reserved)->toBe(2.0);
    expect($items['Card']->fresh()->quantity_reserved)->toBe(2.0);        // money bag's recipe
    expect($items['Box']->fresh()->quantity_reserved)->toBe(2.0);
    expect($items['Poly']->fresh()->quantity_reserved)->toBe(2.0);        // combo's direct item

    // Product ledger rows are written too.
    expect(ItemMovement::where('product_id', $products['belt']->id)->where('movement_type', 'reserve')->exists())->toBeTrue();
});

test('ordering a product deducts its own finished-goods stock on confirm', function () {
    [$org, $store, $items, $products] = comboFixture();
    [$d1, $e1] = comboEvent(comboOrder(1, 'BELT', 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = comboEvent(comboOrder(1, 'BELT', 'completed', 2), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($products['belt']->fresh()->quantity_on_hand)->toBe(8.0);  // 10 - 2
    expect($items['Leather']->fresh()->quantity_on_hand)->toBe(18.0); // 20 - 2
    expect($items['Buckle']->fresh()->quantity_on_hand)->toBe(18.0);
});

test('reserve always shows the full demand even when stock is short', function () {
    [$org, $store, $items, $products] = comboFixture();

    [$data, $event] = comboEvent(comboOrder(1, 'BELT', 'processing', 99), $org, $store, 'e1');
    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    // The reserved column shows the TRUE demand (99), not a clamp to stock.
    expect($products['belt']->fresh()->quantity_reserved)->toBe(99.0);
    expect($products['belt']->fresh()->available())->toBe(-89.0); // 10 - 99 → shortfall visible
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(99.0);
    expect($result['reservation_state'])->toBe('partial'); // under-covered, not falsely 'reserved'
});

test('an unmapped order stays none/unmapped and self-heals once the SKU exists', function () {
    [$org, $store, $items, $products] = comboFixture();

    [$data, $event] = comboEvent(comboOrder(1, 'NOPE', 'processing', 1), $org, $store, 'e1');
    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect($result['reservation_state'])->toBe('none'); // never stamped reserved
    expect(Order::first()->sync_status)->toBe('unmapped');
    expect(Order::first()->components()->count())->toBe(0);
    expect(ItemMovement::where('ref_type', 'order')->count())->toBe(0); // no ledger writes

    // Add the product now (with a recipe) — the next event reserves it.
    $product = Product::create(['organization_id' => $org->id, 'name' => 'Late product', 'sku' => 'NOPE', 'source' => 'manual', 'quantity_on_hand' => 5]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Card']->id, 'component_type' => 'item', 'quantity' => 1]);

    [$d2, $e2] = comboEvent(comboOrder(1, 'NOPE', 'processing', 1), $org, $store, 'e2');
    $result2 = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($result2['reservation_state'])->toBe('reserved');
    expect($items['Card']->fresh()->quantity_reserved)->toBe(1.0);
    expect(Order::first()->sync_status)->toBe('processed');
});

test('a recipe added after the first reserve event catches up on the next event', function () {
    [$org, $store, $items, $products] = comboFixture();

    // Strip the belt's recipe so the first event only reserves the belt itself.
    $products['belt']->bomLines()->delete();

    [$d1, $e1] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e1');
    $result = app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);

    expect($result['reservation_state'])->toBe('reserved');
    expect($products['belt']->fresh()->quantity_reserved)->toBe(1.0);
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(0.0); // recipe didn't exist yet

    BomLine::create(['organization_id' => $org->id, 'product_id' => $products['belt']->id, 'item_id' => $items['Leather']->id, 'component_type' => 'item', 'quantity' => 1]);

    [$d2, $e2] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e2');
    $result2 = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($result2['action'])->toBe('reserve'); // idempotent catch-up
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(1.0);
    expect($products['belt']->fresh()->quantity_reserved)->toBe(1.0); // not double-reserved
});

test('a circular recipe is stopped and flagged as an error', function () {
    [$org, $store, $items, $products] = comboFixture();

    $a = Product::create(['organization_id' => $org->id, 'name' => 'A', 'sku' => 'A', 'source' => 'manual', 'quantity_on_hand' => 5]);
    $b = Product::create(['organization_id' => $org->id, 'name' => 'B', 'sku' => 'B', 'source' => 'manual', 'quantity_on_hand' => 5]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $a->id, 'component_product_id' => $b->id, 'component_type' => 'product', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $b->id, 'component_product_id' => $a->id, 'component_type' => 'product', 'quantity' => 1]);

    [$data, $event] = comboEvent(comboOrder(1, 'A', 'processing', 1), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect(Order::first()->sync_status)->toBe('error');
    expect(Order::first()->components()->count())->toBe(2); // A self + B self, no infinite loop
});

test('matching prefers the product row that has a recipe', function () {
    [$org, $store, $items, $products] = comboFixture();

    // A recipe-less synced row sharing the same SKU as the manual recipe row.
    Product::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woo_product_id' => 999,
        'source' => 'woo',
        'name' => 'Belt (synced)',
        'sku' => 'BELT',
    ]);

    // The order line carries the woo product id (matches the synced row) AND the SKU.
    [$data, $event] = comboEvent(comboOrder(1, 'BELT', 'processing', 1, 999), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    $orderItem = Order::first()->items()->first();

    expect($orderItem->product_id)->toBe($products['belt']->id); // the recipe row wins
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(1.0);
});

test('matches by exact product name when the store has no SKUs', function () {
    [$org, $store, $items, $products] = comboFixture();

    // No SKU on the order line, and the woo id matches nothing — but the name
    // is exact, so the belt product is found and reserved.
    $data = comboOrder(1, 'Belt', 'processing', 1, 999999);
    $data['items'][0]['sku'] = '';
    [$orderData, $event] = comboEvent($data, $org, $store, 'e1');
    $result = app(InventoryEngine::class)->processOrderEvent($orderData, $store, $event);

    expect($result['reservation_state'])->toBe('reserved');
    expect(Order::first()->items()->first()->product_id)->toBe($products['belt']->id);
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(1.0);
});

test('order items from a later event populate an order created with no items', function () {
    [$org, $store, $items, $products] = comboFixture();

    // First event: Woo fires woocommerce_new_order before line items attach → empty items.
    $empty = comboOrder(1, 'Belt', 'on-hold', 1);
    $empty['items'] = [];
    [$d1, $e1] = comboEvent($empty, $org, $store, 'e1');
    $result1 = app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);

    expect(Order::first()->items()->count())->toBe(0);

    // Second event (e.g. status change): the full item list arrives.
    [$d2, $e2] = comboEvent(comboOrder(1, 'Belt', 'on-hold', 1), $org, $store, 'e2');
    $result2 = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect(Order::first()->items()->count())->toBe(1);
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(1.0);
    expect($result2['reservation_state'])->toBe('reserved');
});

test('product stock counts in asset value and manual adjustments write ledger rows', function () {
    [$org, $store, $items, $products] = comboFixture();

    // Items (190 × 10) + belt (10 × 100) + money bag (8 × 50) + combo (5 × 200).
    expect(app(AssetValuation::class)->total($org->id))->toBe(4300.0);

    $user = User::create(['organization_id' => $org->id, 'name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret', 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->postJson("/api/products/{$products['belt']->id}/adjust", ['type' => 'add', 'quantity' => '2', 'reason' => 'purchase'])
        ->assertOk();

    expect(ItemMovement::where('product_id', $products['belt']->id)->where('movement_type', 'adjustment')->count())->toBe(1);
    expect($products['belt']->fresh()->quantity_on_hand)->toBe(12.0);
});

test('deleting an order releases its reservations and removes the record', function () {
    [$org, $store, $items, $products] = comboFixture();
    [$data, $event] = comboEvent(comboOrder(1, 'BELT', 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect($products['belt']->fresh()->quantity_reserved)->toBe(2.0);

    $user = User::create(['organization_id' => $org->id, 'name' => 'Admin', 'email' => 'a@t.dev', 'password' => 'secret', 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->deleteJson('/api/orders/'.Order::first()->id)->assertOk();

    expect(Order::count())->toBe(0);
    expect($products['belt']->fresh()->quantity_reserved)->toBe(0.0); // released
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(0.0);

    // The synthetic delete event must not be left looking unprocessed.
    $deleteEvent = WebhookEvent::where('event_id', 'like', 'delete-%')->first();
    expect($deleteEvent)->not->toBeNull();
    expect($deleteEvent->status)->toBeIn(['processed', 'noop']);
});

test('bulk reprocess applies to selected orders', function () {
    [$org, $store, $items, $products] = comboFixture();

    [$d1, $e1] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = comboEvent(comboOrder(2, 'BELT', 'processing', 1), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    $user = User::create(['organization_id' => $org->id, 'name' => 'Admin', 'email' => 'a@t.dev', 'password' => 'secret', 'role' => 'admin']);
    Sanctum::actingAs($user);

    $ids = Order::pluck('id')->all();

    $this->postJson('/api/orders/bulk', ['action' => 'reprocess', 'ids' => $ids])
        ->assertOk()
        ->assertJsonCount(2, 'results');

    expect(WebhookEvent::where('event_id', 'like', 'reprocess-%')->count())->toBe(2);
    expect(WebhookEvent::where('event_id', 'like', 'reprocess-%')->where('status', 'received')->count())->toBe(0);
});

test('bulk delete removes selected orders and releases their stock', function () {
    [$org, $store, $items, $products] = comboFixture();

    [$d1, $e1] = comboEvent(comboOrder(1, 'BELT', 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = comboEvent(comboOrder(2, 'BELT', 'processing', 2), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    $user = User::create(['organization_id' => $org->id, 'name' => 'Admin', 'email' => 'a@t.dev', 'password' => 'secret', 'role' => 'admin']);
    Sanctum::actingAs($user);

    $ids = Order::pluck('id')->all();

    $this->postJson('/api/orders/bulk', ['action' => 'delete', 'ids' => $ids])->assertOk();

    expect(Order::count())->toBe(0);
    expect($products['belt']->fresh()->quantity_reserved)->toBe(0.0);

    expect(WebhookEvent::where('event_id', 'like', 'delete-%')->count())->toBe(2);
    expect(WebhookEvent::where('event_id', 'like', 'delete-%')->where('status', 'received')->count())->toBe(0);
});

test('reprocessing tops up a zero-stock component to its full demand', function () {
    [$org, $store, $items, $products] = comboFixture();

    // Add a zero-stock item to the belt's recipe.
    $logo = Item::create(['organization_id' => $org->id, 'name' => 'Logo', 'unit' => 'pcs', 'cost_price' => 3, 'quantity_on_hand' => 0, 'low_stock_threshold' => 5]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $products['belt']->id, 'item_id' => $logo->id, 'component_type' => 'item', 'quantity' => 1]);

    [$d1, $e1] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);

    // Full demand is reserved even at zero stock — the shortfall is visible.
    expect($logo->fresh()->quantity_reserved)->toBe(1.0);
    expect($logo->fresh()->available)->toBe(-1.0);

    // Simulate the old clamped state: no reserve ledger row for this item, and
    // the component + cache both at 0 (as the pre-full-demand code produced).
    ItemMovement::where('ref_type', 'order')->where('movement_type', 'reserve')->where('item_id', $logo->id)->delete();
    \App\Models\OrderItemComponent::where('item_id', $logo->id)->update(['reserved_quantity' => 0, 'status' => 'partial']);
    $logo->fresh()->update(['quantity_reserved' => 0]);

    [$d2, $e2] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($logo->fresh()->quantity_reserved)->toBe(1.0); // topped back up to full demand
    expect(\App\Models\OrderItemComponent::where('item_id', $logo->id)->first()->reserved_quantity)->toBe(1.0);
});

test('reprocessing an order repeatedly never increases its reservations', function () {
    [$org, $store, $items, $products] = comboFixture();

    // A zero-stock item keeps the order 'partial', so reprocess re-enters reserve.
    $logo = Item::create(['organization_id' => $org->id, 'name' => 'Logo', 'unit' => 'pcs', 'cost_price' => 3, 'quantity_on_hand' => 0, 'low_stock_threshold' => 5]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $products['belt']->id, 'item_id' => $logo->id, 'component_type' => 'item', 'quantity' => 1]);

    [$d1, $e1] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);

    expect($logo->fresh()->quantity_reserved)->toBe(1.0);

    // Reprocess twice more — reserved must stay exactly the same.
    [$d2, $e2] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);
    [$d3, $e3] = comboEvent(comboOrder(1, 'BELT', 'processing', 1), $org, $store, 'e3');
    app(InventoryEngine::class)->processOrderEvent($d3, $store, $e3);

    expect($logo->fresh()->quantity_reserved)->toBe(1.0);
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(1.0);
    expect(Order::first()->components()->count())->toBe(4); // self + leather + buckle + logo
});

test('the settings test-email endpoint sends to the configured address', function () {
    [$org, $store, $items, $products] = comboFixture();
    Mail::fake();

    $user = User::create(['organization_id' => $org->id, 'name' => 'Admin', 'email' => 'a@t.dev', 'password' => 'secret', 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->postJson('/api/settings/test-email')
        ->assertOk()
        ->assertJsonPath('sent_to', 'alerts@test.dev');
});

/**
 * A combo whose sub-products ALSO carry the same packing items in their recipes —
 * the production "3x" scenario. The combo's own direct lines must win.
 */
function overlapComboFixture(): array
{
    $org = Organization::create(['name' => 'Overlap Org', 'low_stock_email' => 'alerts@test.dev']);
    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Store',
        'store_url' => 'https://shop.example.com',
        'api_key' => 'sp_key',
        'api_secret' => 'secretsecretsecretsecretsecretsecret',
    ]);

    $items = [];
    foreach (['Leather' => 20, 'Card' => 50, 'Poly' => 50, 'Sticker' => 50] as $name => $qty) {
        $items[$name] = Item::create([
            'organization_id' => $org->id,
            'name' => $name,
            'unit' => 'pcs',
            'cost_price' => 10,
            'quantity_on_hand' => $qty,
            'low_stock_threshold' => 5,
        ]);
        ItemMovement::create([
            'organization_id' => $org->id,
            'item_id' => $items[$name]->id,
            'movement_type' => 'initial',
            'qty_delta' => $qty,
            'reserved_delta' => 0,
            'on_hand_after' => $qty,
            'reserved_after' => 0,
            'ref_type' => 'seed',
            'cost_price_at_movement' => 10,
            'reason' => 'initial',
        ]);
    }

    $belt = Product::create(['organization_id' => $org->id, 'name' => 'Belt', 'sku' => 'BELT', 'source' => 'manual', 'quantity_on_hand' => 10]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $belt->id, 'item_id' => $items['Leather']->id, 'component_type' => 'item', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $belt->id, 'item_id' => $items['Poly']->id, 'component_type' => 'item', 'quantity' => 1]);

    $wallet = Product::create(['organization_id' => $org->id, 'name' => 'Wallet', 'sku' => 'WAL', 'source' => 'manual', 'quantity_on_hand' => 8]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $wallet->id, 'item_id' => $items['Card']->id, 'component_type' => 'item', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $wallet->id, 'item_id' => $items['Poly']->id, 'component_type' => 'item', 'quantity' => 1]);

    // Combo lists the packing items directly AND references both sub-products.
    $combo = Product::create(['organization_id' => $org->id, 'name' => 'Belt + Wallet Combo', 'sku' => 'COMBO', 'source' => 'manual', 'quantity_on_hand' => 5]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'component_product_id' => $belt->id, 'component_type' => 'product', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'component_product_id' => $wallet->id, 'component_type' => 'product', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'item_id' => $items['Poly']->id, 'component_type' => 'item', 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $combo->id, 'item_id' => $items['Sticker']->id, 'component_type' => 'item', 'quantity' => 1]);

    return [$org, $store, $items, ['belt' => $belt, 'wallet' => $wallet, 'combo' => $combo]];
}

test('a combo direct item line wins over the same item its sub-products also consume', function () {
    [$org, $store, $items, $products] = overlapComboFixture();
    [$data, $event] = comboEvent(comboOrder(1, 'COMBO', 'processing', 1), $org, $store, 'e1');

    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    // Poly is listed directly on the combo (×1) — belt's and wallet's copies must
    // NOT stack on top of it. This is the production bug that reserved 3×.
    expect($items['Poly']->fresh()->quantity_reserved)->toBe(1.0);
    // Items the combo does NOT list directly still come from each sub-product.
    expect($items['Leather']->fresh()->quantity_reserved)->toBe(1.0); // belt's recipe
    expect($items['Card']->fresh()->quantity_reserved)->toBe(1.0);    // wallet's recipe
    // The combo's own unique item.
    expect($items['Sticker']->fresh()->quantity_reserved)->toBe(1.0);
    // Sub-product finished-goods stock is still reserved.
    expect($products['belt']->fresh()->quantity_reserved)->toBe(1.0);
    expect($products['wallet']->fresh()->quantity_reserved)->toBe(1.0);

    expect($result['reservation_state'])->toBe('reserved');
});

test('sub-product items are still additive when the combo does not list them directly', function () {
    [$org, $store, $items, $products] = overlapComboFixture();

    // Remove the combo's direct Poly line → now only belt + wallet contribute it.
    BomLine::where('product_id', $products['combo']->id)->where('item_id', $items['Poly']->id)->delete();

    [$data, $event] = comboEvent(comboOrder(1, 'COMBO', 'processing', 1), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    // Not owned → the poly from belt (1) and wallet (1) both count: 2.
    expect($items['Poly']->fresh()->quantity_reserved)->toBe(2.0);
    expect($items['Sticker']->fresh()->quantity_reserved)->toBe(1.0); // combo's own direct line unaffected
});
