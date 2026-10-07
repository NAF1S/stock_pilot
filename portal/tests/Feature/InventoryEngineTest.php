<?php

use App\Mail\LowStockAlert;
use App\Models\BomLine;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockWarning;
use App\Models\StoreConnection;
use App\Models\WebhookEvent;
use App\Services\AssetValuation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Build an org with a Belt Kit product whose recipe is:
 *   1 × Belt, 2 × Card, 1 × Sticker
 */
function engineFixture(): array
{
    $org = Organization::create(['name' => 'Test Org', 'low_stock_email' => 'alerts@test.dev']);
    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Test Store',
        'store_url' => 'https://test.example.com',
        'api_key' => 'sp_testkey',
        'api_secret' => 'secretsecretsecretsecretsecretsecret',
    ]);

    $specs = ['Belt' => 50, 'Card' => 500, 'Sticker' => 1000];
    $items = [];

    foreach ($specs as $name => $qty) {
        $items[$name] = Item::create([
            'organization_id' => $org->id,
            'name' => $name,
            'unit' => 'pcs',
            'cost_price' => 10,
            'quantity_on_hand' => $qty,
            'low_stock_threshold' => 10,
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

    // Finished-goods stock: a product order also consumes the product's own units.
    $product = Product::create(['organization_id' => $org->id, 'name' => 'Belt Kit', 'sku' => 'KIT-1', 'source' => 'manual', 'quantity_on_hand' => 5]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Belt']->id, 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Card']->id, 'quantity' => 2]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Sticker']->id, 'quantity' => 1]);

    return [$org, $store, $items, $product];
}

function engineOrder(array $orderData, Organization $org, StoreConnection $store, string $eventId): array
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

function baseOrder(int $id, string $status, int $qty = 2): array
{
    return [
        'id' => $id,
        'number' => (string) $id,
        'status' => $status,
        'date_created' => now()->toIso8601String(),
        'date_modified' => now()->toIso8601String(),
        'customer' => ['name' => 'Test', 'email' => 'test@dev'],
        'currency' => 'BDT',
        'totals' => ['subtotal' => 100, 'total' => 100],
        'items' => [
            ['id' => 1, 'product_id' => 1, 'name' => 'Belt Kit', 'sku' => 'KIT-1', 'quantity' => $qty, 'price' => 100, 'line_total' => 100 * $qty],
        ],
        'refunds' => [],
    ];
}

test('reserves components when a paid order arrives', function () {
    [$org, $store, $items, $product] = engineFixture();
    [$data, $event] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e1');

    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect($result['action'])->toBe('reserve');
    expect($result['reservation_state'])->toBe('reserved');
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(2.0);
    expect($items['Card']->fresh()->quantity_reserved)->toBe(4.0); // 2 per unit
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(50.0); // unchanged
    // The product's OWN finished-goods units are reserved too (self component).
    expect($product->fresh()->quantity_reserved)->toBe(2.0);
});

test('confirms components when the order is completed', function () {
    [$org, $store, $items] = engineFixture();
    [$d1, $e1] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = engineOrder(baseOrder(1, 'completed', 2), $org, $store, 'e2');

    $result = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($result['action'])->toBe('confirm');
    expect($result['reservation_state'])->toBe('confirmed');
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(48.0);
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(0.0);
    expect($items['Card']->fresh()->quantity_on_hand)->toBe(496.0);
});

test('releases reservations when an order is cancelled', function () {
    [$org, $store, $items] = engineFixture();
    [$d1, $e1] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = engineOrder(baseOrder(1, 'cancelled', 2), $org, $store, 'e2');

    $result = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($result['action'])->toBe('release');
    expect($result['reservation_state'])->toBe('released');
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(0.0);
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(50.0);
});

test('restocks and records the charge on returned-with-charge', function () {
    [$org, $store, $items] = engineFixture();
    [$d1, $e1] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = engineOrder(baseOrder(1, 'completed', 2), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    $returned = baseOrder(1, 'returned-with-charge', 2);
    $returned['return_charge'] = 100;
    $returned['refunds'] = [['id' => 1, 'reason' => 'size', 'total' => 100, 'items' => [['item_id' => 1, 'quantity' => 2, 'total' => 100]]]];
    [$d3, $e3] = engineOrder($returned, $org, $store, 'e3');

    $result = app(InventoryEngine::class)->processOrderEvent($d3, $store, $e3);

    expect($result['action'])->toBe('return');
    expect($result['reservation_state'])->toBe('returned');
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(50.0); // restored
    expect(Order::where('woocommerce_id', 1)->first()->return_charge)->toBe(100.0);
    expect(OrderItemComponent::where('item_id', $items['Belt']->id)->first()->status)->toBe('returned');
});

test('ignores duplicate status events without double-deducting', function () {
    [$org, $store, $items] = engineFixture();
    [$d1, $e1] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e2');
    $result = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    // ['reserved','reserve'] is an idempotent catch-up — action is 'reserve' but
    // already-reserved components are skipped, so nothing is double-reserved.
    expect($result['action'])->toBe('reserve');
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(2.0); // not 4
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(50.0);

    [$d3, $e3] = engineOrder(baseOrder(1, 'completed', 2), $org, $store, 'e3');
    app(InventoryEngine::class)->processOrderEvent($d3, $store, $e3);
    [$d4, $e4] = engineOrder(baseOrder(1, 'completed', 2), $org, $store, 'e4');
    $result2 = app(InventoryEngine::class)->processOrderEvent($d4, $store, $e4);

    expect($result2['action'])->toBe('noop');
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(48.0); // deducted once
});

test('handles completed arriving before processing (direct confirm)', function () {
    [$org, $store, $items] = engineFixture();
    [$data, $event] = engineOrder(baseOrder(1, 'completed', 1), $org, $store, 'e1');

    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect($result['action'])->toBe('confirm');
    expect($result['reservation_state'])->toBe('confirmed');
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(49.0);
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(0.0);
});

test('reserves partially when stock is insufficient', function () {
    [$org, $store, $items] = engineFixture();
    $items['Belt']->update(['quantity_on_hand' => 1]);

    $data = baseOrder(1, 'processing', 2);
    [$d, $e] = engineOrder($data, $org, $store, 'e1');

    $result = app(InventoryEngine::class)->processOrderEvent($d, $store, $e);

    expect($result['reservation_state'])->toBe('partial');
    // The FULL demand is reserved even when short, so the shortfall is visible.
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(2.0);
    expect($items['Belt']->fresh()->available)->toBe(-1.0);
    expect(OrderItemComponent::where('item_id', $items['Belt']->id)->first()->status)->toBe('partial');
});

test('writes an append-only ledger with reversal pointers', function () {
    [$org, $store] = engineFixture();
    [$d1, $e1] = engineOrder(baseOrder(1, 'processing', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);
    [$d2, $e2] = engineOrder(baseOrder(1, 'cancelled', 2), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    $release = ItemMovement::where('movement_type', 'release')->get();
    $reserves = ItemMovement::where('movement_type', 'reserve')->pluck('id');

    $reversalIds = $release->pluck('reversal_of');

    expect($reversalIds)->each->not->toBeNull();
    foreach ($reversalIds as $reversalId) {
        expect($reserves)->toContain($reversalId);
    }
});

test('asset value equals sum of on-hand × cost', function () {
    [$org, $store, $items] = engineFixture();
    $total = app(AssetValuation::class)->total($org->id);
    $expected = collect($items)->sum(fn (Item $i) => $i->fresh()->quantity_on_hand * $i->cost_price);

    expect($total)->toBe($expected);
});

test('reserves components on a custom confirmed status', function () {
    [$org, $store, $items] = engineFixture();
    [$data, $event] = engineOrder(baseOrder(1, 'confirmed', 2), $org, $store, 'e1');

    $result = app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    expect($result['action'])->toBe('reserve');
    expect($result['reservation_state'])->toBe('reserved');
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(2.0);
});

test('does not double-reserve when on-hold then confirmed', function () {
    [$org, $store, $items] = engineFixture();
    [$d1, $e1] = engineOrder(baseOrder(1, 'on-hold', 2), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d1, $store, $e1);

    [$d2, $e2] = engineOrder(baseOrder(1, 'confirmed', 2), $org, $store, 'e2');
    $result = app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect($result['action'])->toBe('reserve'); // idempotent catch-up, not double-reserve
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(2.0); // not 4
});

test('reprocess reserves an order stuck at none after a status-map change', function () {
    [$org, $store, $items] = engineFixture();
    $data = baseOrder(1, 'confirmed', 2);

    // Simulate an order synced before 'confirmed' was in the reserve map.
    $order = Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => $data['id'],
        'woocommerce_number' => (string) $data['id'],
        'status' => $data['status'],
        'reservation_state' => 'none',
        'payload' => $data,
        'sync_status' => 'processed',
    ]);

    foreach ($data['items'] as $index => $item) {
        OrderItem::create([
            'order_id' => $order->id,
            'woocommerce_item_id' => $item['id'],
            'product_id' => \App\Models\Product::where('sku', 'KIT-1')->value('id'),
            'product_name' => $item['name'],
            'sku' => $item['sku'],
            'quantity' => $item['quantity'],
            'price' => $item['price'],
            'line_total' => $item['line_total'],
            'sort' => $index,
        ]);
    }

    // Reprocess = re-run the stored payload through the engine.
    $event = WebhookEvent::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_order_id' => $data['id'],
        'event_id' => 'reprocess-test-1',
        'nonce' => Str::random(32),
        'event_type' => 'order.sync',
        'payload' => json_encode(['order' => $data]),
        'status' => 'received',
    ]);

    $result = app(InventoryEngine::class)->processOrderEvent($order->payload, $store, $event);

    expect($result['action'])->toBe('reserve');
    expect($order->fresh()->reservation_state)->toBe('reserved');
    expect($items['Belt']->fresh()->quantity_reserved)->toBe(2.0);
});

test('creates a low-stock warning for a product', function () {
    Mail::fake();
    [$org, $store, $items] = engineFixture();

    $product = Product::create([
        'organization_id' => $org->id,
        'name' => 'Belt Kit',
        'sku' => 'KIT-1',
        'source' => 'manual',
        'cost' => 200,
        'quantity_on_hand' => 5,
        'low_stock_threshold' => 10,
    ]);

    app(\App\Services\LowStockNotifier::class)->evaluateProduct($product->id, $org->id);

    expect(StockWarning::where('product_id', $product->id)->where('status', 'active')->count())->toBe(1);
    Mail::assertSent(LowStockAlert::class, 1);
});

test('creates a low-stock warning and sends one email', function () {
    Mail::fake();
    [$org, $store, $items] = engineFixture();
    $items['Belt']->update(['low_stock_threshold' => 50]); // already at threshold

    [$d, $e] = engineOrder(baseOrder(1, 'processing', 1), $org, $store, 'e1');
    app(InventoryEngine::class)->processOrderEvent($d, $store, $e);
    [$d2, $e2] = engineOrder(baseOrder(1, 'completed', 1), $org, $store, 'e2');
    app(InventoryEngine::class)->processOrderEvent($d2, $store, $e2);

    expect(StockWarning::where('item_id', $items['Belt']->id)->where('status', 'active')->count())->toBe(1);
    Mail::assertSent(LowStockAlert::class, 1);
});
