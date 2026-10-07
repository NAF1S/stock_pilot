<?php

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function activityUser(Organization $org): User
{
    return User::create([
        'organization_id' => $org->id,
        'name' => 'Admin',
        'email' => 'admin@activity.dev',
        'password' => 'password',
        'role' => 'admin',
        'is_active' => true,
    ]);
}

function activityMovement(Organization $org, ?Item $item, ?Product $product, string $type, float $qty, string $refType, ?int $refId, ?string $reason = null, ?int $createdBy = null): ItemMovement
{
    return ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item?->id,
        'product_id' => $product?->id,
        'movement_type' => $type,
        'qty_delta' => $qty,
        'reserved_delta' => 0,
        'on_hand_after' => 0,
        'reserved_after' => 0,
        'ref_type' => $refType,
        'ref_id' => $refId,
        'reason' => $reason,
        'created_by' => $createdBy,
    ]);
}

test('the activity log lists item and product movements with their order reference', function () {
    $org = Organization::create(['name' => 'Activity Org']);
    $user = activityUser($org);
    $item = Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'cost_price' => 10, 'quantity_on_hand' => 46]);
    $product = Product::create(['organization_id' => $org->id, 'name' => 'Belt Kit', 'sku' => 'KIT-1', 'source' => 'manual', 'quantity_on_hand' => 5]);
    $order = Order::create(['organization_id' => $org->id, 'woocommerce_id' => 1024, 'woocommerce_number' => '1024', 'status' => 'completed', 'reservation_state' => 'confirmed']);

    activityMovement($org, $item, null, 'confirm', -2, 'order', $order->id, 'Confirmed', $user->id);
    activityMovement($org, null, $product, 'return', 1, 'order', $order->id, 'Returned', $user->id);
    activityMovement($org, $item, null, 'adjustment', 5, 'adjustment', $item->id, 'Stock added', $user->id);

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/movements');

    $response->assertOk();
    $rows = $response->json('data');

    expect($rows)->toHaveCount(3);
    expect($response->json('total'))->toBe(3);

    // Order-driven row resolves to the WooCommerce order number + status.
    $confirm = collect($rows)->firstWhere('movement_type', 'confirm');
    expect($confirm['entity_type'])->toBe('item');
    expect($confirm['entity_name'])->toBe('Belt');
    expect($confirm['ref_label'])->toBe('#1024');
    expect($confirm['ref_order_status'])->toBe('completed');

    // Product row exposes the product entity.
    $return = collect($rows)->firstWhere('movement_type', 'return');
    expect($return['entity_type'])->toBe('product');
    expect($return['entity_name'])->toBe('Belt Kit');
    expect((float) $return['qty_delta'])->toBe(1.0);

    // The actor is resolved too.
    $adj = collect($rows)->firstWhere('movement_type', 'adjustment');
    expect($adj['created_by'])->toBe('Admin');
});

test('the activity log can filter by entity, movement type and source', function () {
    $org = Organization::create(['name' => 'Activity Org']);
    $user = activityUser($org);
    $item = Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'cost_price' => 10, 'quantity_on_hand' => 46]);
    $order = Order::create(['organization_id' => $org->id, 'woocommerce_id' => 1024, 'woocommerce_number' => '1024', 'status' => 'completed', 'reservation_state' => 'confirmed']);

    activityMovement($org, $item, null, 'reserve', 0, 'order', $order->id);
    activityMovement($org, $item, null, 'confirm', -2, 'order', $order->id);
    activityMovement($org, $item, null, 'adjustment', 5, 'adjustment', $item->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/movements?entity=product')->assertJsonCount(0, 'data');
    $this->getJson('/api/movements?movement_type=confirm')->assertJsonCount(1, 'data');
    $this->getJson('/api/movements?ref_type=order')->assertJsonCount(2, 'data');
    $this->getJson('/api/movements?movement_type=confirm&ref_type=order')->assertJsonCount(1, 'data');
    $this->getJson('/api/movements?q=Belt')->assertJsonCount(3, 'data');
});

test('the activity log is scoped to the organization', function () {
    $orgA = Organization::create(['name' => 'Org A']);
    $orgB = Organization::create(['name' => 'Org B']);
    $userA = activityUser($orgA);
    $itemA = Item::create(['organization_id' => $orgA->id, 'name' => 'Belt', 'unit' => 'pcs', 'cost_price' => 10, 'quantity_on_hand' => 46]);
    $itemB = Item::create(['organization_id' => $orgB->id, 'name' => 'Wallet', 'unit' => 'pcs', 'cost_price' => 5, 'quantity_on_hand' => 20]);

    activityMovement($orgA, $itemA, null, 'adjustment', 1, 'adjustment', $itemA->id);
    activityMovement($orgB, $itemB, null, 'adjustment', 1, 'adjustment', $itemB->id);

    Sanctum::actingAs($userA);
    $this->getJson('/api/movements')->assertJsonCount(1, 'data');
});

test('the asset report includes finished-goods values and split totals', function () {
    $org = Organization::create(['name' => 'Asset Org']);
    $user = activityUser($org);

    Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'cost_price' => 10, 'quantity_on_hand' => 4]);
    Product::create(['organization_id' => $org->id, 'name' => 'Belt Kit', 'sku' => 'KIT-1', 'source' => 'manual', 'cost' => 100, 'quantity_on_hand' => 3]);

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/reports/asset-value');

    $response->assertOk();
    expect((float) $response->json('materials_total'))->toBe(40.0);
    expect((float) $response->json('finished_total'))->toBe(300.0);
    expect((float) $response->json('total'))->toBe(340.0);
    expect($response->json('by_product'))->toHaveCount(1);
    expect($response->json('by_product.0.name'))->toBe('Belt Kit');
    expect((float) $response->json('by_product.0.value'))->toBe(300.0);
    expect((float) $response->json('by_item.0.value'))->toBe(40.0);
});
