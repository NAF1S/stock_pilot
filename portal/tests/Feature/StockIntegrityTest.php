<?php

use App\Models\BomLine;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItemComponent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Self-contained engine fixture: Belt Kit product = 1×Belt + 2×Card + 1×Sticker.
 */
function integrityFixture(): array
{
    Mail::fake();

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
    }

    $product = Product::create([
        'organization_id' => $org->id,
        'name' => 'Belt Kit',
        'sku' => 'KIT-1',
        'source' => 'manual',
        'quantity_on_hand' => 5,
    ]);

    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Belt']->id, 'quantity' => 1]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Card']->id, 'quantity' => 2]);
    BomLine::create(['organization_id' => $org->id, 'product_id' => $product->id, 'item_id' => $items['Sticker']->id, 'quantity' => 1]);

    return [$org, $store, $items, $product];
}

function integrityEvent(array $orderData, Organization $org, StoreConnection $store, string $eventId): void
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

    app(InventoryEngine::class)->processOrderEvent($orderData, $store, $event);
}

function integrityOrder(int $id, string $status, int $qty = 2): array
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

test('a completed order is never re-deducted when its component rows reappear as pending', function () {
    [$org, $store, $items] = integrityFixture();

    integrityEvent(integrityOrder(1, 'processing', 2), $org, $store, 'e1');
    integrityEvent(integrityOrder(1, 'completed', 2), $org, $store, 'e2');

    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(48.0);

    // Reproduce the production drift: the order's component rows disappear
    // (cascade delete on a remap / re-import). The next completed event then
    // re-materializes them as 'pending' and would normally deduct AGAIN.
    $order = Order::where('woocommerce_id', 1)->first();
    OrderItemComponent::where('order_id', $order->id)->delete();

    integrityEvent(integrityOrder(1, 'completed', 2), $org, $store, 'e3');

    // The ledger proves this order already confirmed these entities — no re-deduct.
    expect($items['Belt']->fresh()->quantity_on_hand)->toBe(48.0);
    expect($items['Card']->fresh()->quantity_on_hand)->toBe(496.0);
    expect($items['Sticker']->fresh()->quantity_on_hand)->toBe(998.0);
    expect(ItemMovement::where('ref_type', 'order')->where('movement_type', 'confirm')->where('item_id', $items['Belt']->id)->count())->toBe(1);
});

test('adding stock back from a negative item balance succeeds', function () {
    $org = Organization::create(['name' => 'Org']);
    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Belt',
        'unit' => 'pcs',
        'quantity_on_hand' => -10,
        'low_stock_threshold' => 5,
    ]);
    $user = User::create([
        'organization_id' => $org->id,
        'name' => 'Admin',
        'email' => 'admin@test.dev',
        'password' => 'password',
        'role' => 'admin',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);
    $response = $this->postJson("/api/items/{$item->id}/adjust", [
        'type' => 'add',
        'quantity' => 1,
        'reason' => 'restock',
    ]);

    $response->assertOk();
    expect($item->fresh()->quantity_on_hand)->toBe(-9.0);
});

test('removing more stock than is on hand still returns 422', function () {
    $org = Organization::create(['name' => 'Org']);
    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Belt',
        'unit' => 'pcs',
        'quantity_on_hand' => -9,
        'low_stock_threshold' => 5,
    ]);
    $user = User::create([
        'organization_id' => $org->id,
        'name' => 'Admin',
        'email' => 'admin@test.dev',
        'password' => 'password',
        'role' => 'admin',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);
    $response = $this->postJson("/api/items/{$item->id}/adjust", [
        'type' => 'remove',
        'quantity' => 5,
        'reason' => 'sale',
    ]);

    $response->assertStatus(422);
    expect($item->fresh()->quantity_on_hand)->toBe(-9.0);
});

test('adding stock back to a negative product succeeds', function () {
    $org = Organization::create(['name' => 'Org']);
    $product = Product::create([
        'organization_id' => $org->id,
        'name' => 'Belt Kit',
        'sku' => 'KIT-1',
        'source' => 'manual',
        'quantity_on_hand' => -10,
    ]);
    $user = User::create([
        'organization_id' => $org->id,
        'name' => 'Admin',
        'email' => 'admin@test.dev',
        'password' => 'password',
        'role' => 'admin',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);
    $response = $this->postJson("/api/products/{$product->id}/adjust", [
        'type' => 'add',
        'quantity' => 1,
        'reason' => 'restock',
    ]);

    $response->assertOk();
    expect($product->fresh()->quantity_on_hand)->toBe(-9.0);
});
