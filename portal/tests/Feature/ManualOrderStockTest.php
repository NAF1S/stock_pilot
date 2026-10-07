<?php

use App\Models\BomLine;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\OrderStatusEvent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function manualFixture(): array
{
    $org = Organization::create(['name' => 'Manual Test Org']);
    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Store 1',
        'store_url' => 'https://store.test',
        'api_key' => 'sp_key',
        'api_secret' => 'secretsecretsecretsecretsecretsecret',
    ]);

    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Leather Strap',
        'unit' => 'pcs',
        'cost_price' => 15,
        'quantity_on_hand' => 10,
        'quantity_reserved' => 0,
        'low_stock_threshold' => 2,
    ]);

    ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'movement_type' => 'initial',
        'qty_delta' => 10,
        'reserved_delta' => 0,
        'on_hand_after' => 10,
        'reserved_after' => 0,
        'ref_type' => 'seed',
        'cost_price_at_movement' => 15,
        'reason' => 'initial',
    ]);

    $product = Product::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'name' => 'Classic Belt',
        'sku' => 'BELT-1',
        'woo_product_id' => 100,
        'source' => 'manual',
        'quantity_on_hand' => 5,
    ]);

    BomLine::create([
        'organization_id' => $org->id,
        'product_id' => $product->id,
        'item_id' => $item->id,
        'quantity' => 2,
    ]);

    $admin = User::create([
        'organization_id' => $org->id,
        'name' => 'Manager User',
        'email' => 'manager@test.dev',
        'password' => 'password',
        'role' => 'manager',
        'is_active' => true,
    ]);

    return [$org, $store, $item, $product, $admin];
}

function createTestOrder(Organization $org, StoreConnection $store, Product $product, int $wooId, int $qty = 1): Order
{
    $order = Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => $wooId,
        'woocommerce_number' => (string) $wooId,
        'status' => 'processing',
        'reservation_state' => 'none',
        'customer_name' => 'John Doe',
        'customer_email' => 'john@test.dev',
        'currency' => 'BDT',
        'total' => 100,
        'payload' => [
            'id' => $wooId,
            'number' => (string) $wooId,
            'status' => 'processing',
            'items' => [
                [
                    'id' => $wooId * 10,
                    'product_id' => $product->woo_product_id,
                    'name' => $product->name,
                    'quantity' => $qty,
                    'price' => 100,
                ],
            ],
        ],
        'sync_status' => 'pending',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'woocommerce_item_id' => $wooId * 10,
        'woocommerce_product_id' => $product->woo_product_id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $qty,
        'price' => 100,
        'line_total' => 100 * $qty,
    ]);

    return $order;
}

test('manager can manually reserve an order', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    $order = createTestOrder($org, $store, $product, 501, 2);

    Sanctum::actingAs($manager);
    $response = $this->postJson("/api/orders/{$order->id}/reserve");

    $response->assertOk();
    $order->refresh();
    $item->refresh();

    expect($order->reservation_state)->toBe('reserved')
        ->and($item->quantity_reserved)->toEqual(4.0) // 2 belts * 2 straps = 4
        ->and(ItemMovement::where('ref_id', $order->id)->where('movement_type', 'reserve')->exists())->toBeTrue()
        ->and(OrderStatusEvent::where('order_id', $order->id)->where('status_to', 'manual:reserve')->exists())->toBeTrue();
});

test('manager can manually confirm an order', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    $order = createTestOrder($org, $store, $product, 502, 2);

    Sanctum::actingAs($manager);
    $response = $this->postJson("/api/orders/{$order->id}/confirm");

    $response->assertOk();
    $order->refresh();
    $item->refresh();

    expect($order->reservation_state)->toBe('confirmed')
        ->and($item->quantity_on_hand)->toEqual(6.0) // 10 - 4 = 6
        ->and($item->quantity_reserved)->toEqual(0.0)
        ->and(ItemMovement::where('ref_id', $order->id)->where('movement_type', 'confirm')->exists())->toBeTrue();
});

test('manager can manually release a reserved order', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    $order = createTestOrder($org, $store, $product, 503, 2);

    Sanctum::actingAs($manager);
    $this->postJson("/api/orders/{$order->id}/reserve")->assertOk();
    $item->refresh();
    expect($item->quantity_reserved)->toEqual(4.0);

    $response = $this->postJson("/api/orders/{$order->id}/release");
    $response->assertOk();

    $order->refresh();
    $item->refresh();

    expect($order->reservation_state)->toBe('released')
        ->and($item->quantity_reserved)->toEqual(0.0)
        ->and(ItemMovement::where('ref_id', $order->id)->where('movement_type', 'release')->exists())->toBeTrue();
});

test('manager can re-reserve a previously released order', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    $order = createTestOrder($org, $store, $product, 504, 2);

    Sanctum::actingAs($manager);
    $this->postJson("/api/orders/{$order->id}/reserve")->assertOk();
    $this->postJson("/api/orders/{$order->id}/release")->assertOk();

    $item->refresh();
    expect($item->quantity_reserved)->toEqual(0.0);

    // Re-reserve
    $response = $this->postJson("/api/orders/{$order->id}/reserve");
    $response->assertOk();

    $order->refresh();
    $item->refresh();

    expect($order->reservation_state)->toBe('reserved')
        ->and($item->quantity_reserved)->toEqual(4.0);
});

test('manager can manually release a confirmed order which restocks inventory', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    $order = createTestOrder($org, $store, $product, 505, 2);

    Sanctum::actingAs($manager);
    $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();
    $item->refresh();
    expect($item->quantity_on_hand)->toEqual(6.0);

    // Release confirmed order -> acts as restock
    $response = $this->postJson("/api/orders/{$order->id}/release");
    $response->assertOk();

    $order->refresh();
    $item->refresh();

    expect($order->reservation_state)->toBe('released')
        ->and($item->quantity_on_hand)->toEqual(10.0) // Restocked back to 10
        ->and(ItemMovement::where('ref_id', $order->id)->where('movement_type', 'return')->exists())->toBeTrue();
});

test('manager can force confirm an order without stock deduction and clear mismatch status', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    // Simulate zero stock on-hand
    $item->quantity_on_hand = 0;
    $item->save();

    $order = createTestOrder($org, $store, $product, 506, 2);
    $order->sync_status = 'mismatch';
    $order->save();

    Sanctum::actingAs($manager);
    $response = $this->postJson("/api/orders/{$order->id}/confirm-no-deduct");

    $response->assertOk();
    $order->refresh();
    $item->refresh();

    expect($order->reservation_state)->toBe('confirmed')
        ->and($order->sync_status)->toBe('processed') // Cleared from mismatch to ok!
        ->and($item->quantity_on_hand)->toEqual(0.0) // Stock was NOT deducted!
        ->and(ItemMovement::where('ref_id', $order->id)->where('movement_type', 'confirm')->where('qty_delta', 0)->exists())->toBeTrue();

    // Verify all components show full confirmed quantity
    $components = OrderItemComponent::where('order_id', $order->id)->get();
    expect($components)->not->toBeEmpty();
    foreach ($components as $c) {
        expect($c->status)->toBe('confirmed')
            ->and($c->confirmed_quantity)->toEqual($c->total_quantity);
    }
});

test('bulk manual actions process multiple orders correctly', function () {
    [$org, $store, $item, $product, $manager] = manualFixture();
    $o1 = createTestOrder($org, $store, $product, 507, 1);
    $o2 = createTestOrder($org, $store, $product, 508, 1);

    Sanctum::actingAs($manager);

    // Bulk Reserve
    $res = $this->postJson('/api/orders/bulk', [
        'action' => 'reserve',
        'ids' => [$o1->id, $o2->id],
    ]);
    $res->assertOk();
    $o1->refresh();
    $o2->refresh();
    expect($o1->reservation_state)->toBe('reserved')
        ->and($o2->reservation_state)->toBe('reserved');

    // Bulk Confirm (no deduct)
    $res2 = $this->postJson('/api/orders/bulk', [
        'action' => 'confirm_no_deduct',
        'ids' => [$o1->id, $o2->id],
    ]);
    $res2->assertOk();
    $o1->refresh();
    $o2->refresh();
    expect($o1->reservation_state)->toBe('confirmed')
        ->and($o1->sync_status)->toBe('processed')
        ->and($o2->reservation_state)->toBe('confirmed');
});

test('unauthorized staff cannot trigger manual stock actions', function () {
    [$org, $store, $item, $product] = manualFixture();
    $staff = User::create([
        'organization_id' => $org->id,
        'name' => 'Staff User',
        'email' => 'staff@test.dev',
        'password' => 'password',
        'role' => 'staff',
        'is_active' => true,
    ]);

    $order = createTestOrder($org, $store, $product, 509, 1);

    Sanctum::actingAs($staff);
    $this->postJson("/api/orders/{$order->id}/reserve")->assertForbidden();
    $this->postJson("/api/orders/{$order->id}/confirm")->assertForbidden();
    $this->postJson("/api/orders/{$order->id}/release")->assertForbidden();
    $this->postJson("/api/orders/{$order->id}/confirm-no-deduct")->assertForbidden();
    $this->postJson('/api/orders/bulk', ['action' => 'reserve', 'ids' => [$order->id]])->assertForbidden();
});
