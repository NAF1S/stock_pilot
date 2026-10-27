<?php

use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function reservationFixture(string $suffix = 'A'): array
{
    $org = Organization::create(['name' => "Reservation Org {$suffix}"]);

    $user = User::create([
        'organization_id' => $org->id,
        'name' => "Reservation Admin {$suffix}",
        'email' => "reservations{$suffix}@example.com",
        'password' => 'secret1234',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => "Reservation Store {$suffix}",
        'store_url' => "https://reservations{$suffix}.example.com",
        'api_key' => "sp_reskey{$suffix}",
        'api_secret' => 'reservationsecretreservationsecret',
    ]);

    return [$org, $user, $store];
}

function reservationOrder(Organization $org, StoreConnection $store, int $wooId, string $status = 'processing'): Order
{
    return Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => $wooId,
        'woocommerce_number' => (string) $wooId,
        'status' => $status,
        'reservation_state' => 'reserved',
        'customer_name' => 'Customer '.$wooId,
        'order_created_at' => now(),
    ]);
}

function reservationComponent(Order $order, array $overrides = []): OrderItemComponent
{
    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'woocommerce_item_id' => (int) $order->items()->max('woocommerce_item_id') + 1,
        'product_name' => 'Order line '.$order->woocommerce_number,
        'quantity' => 1,
    ]);

    return OrderItemComponent::create(array_merge([
        'organization_id' => $order->organization_id,
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'quantity_per_unit' => 1,
        'order_quantity' => 1,
        'total_quantity' => 1,
        'reserved_quantity' => 1,
        'status' => 'reserved',
        'reserved_at' => now(),
    ], $overrides));
}

test('item reservations list only orders actively reserving that item', function () {
    [$org, $user, $store] = reservationFixture();

    $thread = Item::create([
        'organization_id' => $org->id,
        'name' => 'Waxed Thread',
        'sku' => 'THR-1',
        'unit' => 'm',
        'quantity_on_hand' => 100,
    ]);

    $other = Item::create([
        'organization_id' => $org->id,
        'name' => 'Brass Rivet',
        'unit' => 'pcs',
        'quantity_on_hand' => 100,
    ]);

    $active = reservationOrder($org, $store, 9001);
    reservationComponent($active, [
        'item_id' => $thread->id,
        'total_quantity' => 10,
        'reserved_quantity' => 10,
    ]);

    $confirmed = reservationOrder($org, $store, 9002, 'completed');
    $confirmed->reservation_state = 'confirmed';
    $confirmed->save();
    reservationComponent($confirmed, [
        'item_id' => $thread->id,
        'total_quantity' => 5,
        'reserved_quantity' => 0,
        'status' => 'confirmed',
    ]);

    $unrelated = reservationOrder($org, $store, 9003);
    reservationComponent($unrelated, [
        'item_id' => $other->id,
        'total_quantity' => 7,
        'reserved_quantity' => 7,
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/items/{$thread->id}/reservations")
        ->assertOk()
        ->assertJsonCount(1, 'reservations')
        ->assertJsonPath('reservations.0.order_number', '9001')
        ->assertJsonPath('reservations.0.reserved_quantity', 10)
        ->assertJsonPath('reservations.0.customer_name', 'Customer 9001')
        ->assertJsonPath('total_reserved', 10);
});

test('product reservations include direct and sub-component usage', function () {
    [$org, $user, $store] = reservationFixture();

    $belt = Product::create([
        'organization_id' => $org->id,
        'name' => 'Leather Belt',
        'sku' => 'BLT-1',
        'source' => 'manual',
        'quantity_on_hand' => 50,
    ]);

    $combo = Product::create([
        'organization_id' => $org->id,
        'name' => 'Combo Kit',
        'sku' => 'CMB-1',
        'source' => 'manual',
        'quantity_on_hand' => 10,
    ]);

    $direct = reservationOrder($org, $store, 9101);
    reservationComponent($direct, [
        'product_id' => $belt->id,
        'component_product_id' => $belt->id,
        'total_quantity' => 3,
        'reserved_quantity' => 3,
    ]);

    $nested = reservationOrder($org, $store, 9102);
    reservationComponent($nested, [
        'product_id' => $combo->id,
        'component_product_id' => $belt->id,
        'total_quantity' => 2,
        'reserved_quantity' => 2,
    ]);

    $unrelated = reservationOrder($org, $store, 9103);
    reservationComponent($unrelated, [
        'product_id' => $combo->id,
        'component_product_id' => $combo->id,
        'total_quantity' => 9,
        'reserved_quantity' => 9,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/products/{$belt->id}/reservations");

    $response->assertOk()
        ->assertJsonCount(2, 'reservations')
        ->assertJsonPath('total_reserved', 5);

    $rows = collect($response->json('reservations'))->keyBy('order_number');

    expect($rows['9101']['usage'])->toBe('direct');
    expect($rows['9102']['usage'])->toBe('component');
    expect($rows['9102']['sellable_product']['name'])->toBe('Combo Kit');
    expect($rows->has('9103'))->toBeFalse();
});

test('reservation endpoints are tenant scoped', function () {
    [$orgA, , $storeA] = reservationFixture('A');
    [, $userB] = reservationFixture('B');

    $thread = Item::create([
        'organization_id' => $orgA->id,
        'name' => 'Org A Thread',
        'unit' => 'm',
        'quantity_on_hand' => 10,
    ]);

    $belt = Product::create([
        'organization_id' => $orgA->id,
        'name' => 'Org A Belt',
        'sku' => 'A-BLT',
        'source' => 'manual',
        'quantity_on_hand' => 10,
    ]);

    $order = reservationOrder($orgA, $storeA, 9201);
    reservationComponent($order, ['item_id' => $thread->id]);
    reservationComponent($order, [
        'product_id' => $belt->id,
        'component_product_id' => $belt->id,
    ]);

    Sanctum::actingAs($userB);

    $this->getJson("/api/items/{$thread->id}/reservations")->assertNotFound();
    $this->getJson("/api/products/{$belt->id}/reservations")->assertNotFound();
});
