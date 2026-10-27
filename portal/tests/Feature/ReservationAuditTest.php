<?php

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\User;
use App\Services\ReservationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function auditFixture(): array
{
    $org = Organization::create(['name' => 'Audit Org']);

    $user = User::create([
        'organization_id' => $org->id,
        'name' => 'Audit Admin',
        'email' => 'audit@example.com',
        'password' => 'secret1234',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Audit Store',
        'store_url' => 'https://audit.example.com',
        'api_key' => 'sp_auditkey',
        'api_secret' => 'auditsecretsecretsecretsecret',
    ]);

    return [$org, $user, $store];
}

function auditOrder(Organization $org, StoreConnection $store, int $wooId): Order
{
    return Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => $wooId,
        'woocommerce_number' => (string) $wooId,
        'status' => 'processing',
        'reservation_state' => 'reserved',
        'order_created_at' => now(),
    ]);
}

function auditComponent(Order $order, array $overrides): OrderItemComponent
{
    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'woocommerce_item_id' => (int) $order->items()->max('woocommerce_item_id') + 1,
        'product_name' => 'Audit line '.$order->woocommerce_number,
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

function auditMismatchFixture(): array
{
    [$org, , $store] = auditFixture();

    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Audit Item',
        'sku' => 'AUD-1',
        'unit' => 'pcs',
        'quantity_on_hand' => 100,
        'quantity_reserved' => 10,
    ]);

    $product = Product::create([
        'organization_id' => $org->id,
        'name' => 'Audit Product',
        'sku' => 'AUD-P1',
        'source' => 'manual',
        'quantity_on_hand' => 50,
        'quantity_reserved' => 5,
    ]);

    $order = auditOrder($org, $store, 9501);

    auditComponent($order, [
        'item_id' => $item->id,
        'total_quantity' => 8,
        'reserved_quantity' => 8,
    ]);

    auditComponent($order, [
        'product_id' => $product->id,
        'component_product_id' => $product->id,
        'total_quantity' => 5,
        'reserved_quantity' => 5,
    ]);

    ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'movement_type' => 'reserve',
        'qty_delta' => 0,
        'reserved_delta' => 8,
        'on_hand_after' => 100,
        'reserved_after' => 8,
        'ref_type' => 'order',
        'ref_id' => $order->id,
    ]);

    ItemMovement::create([
        'organization_id' => $org->id,
        'product_id' => $product->id,
        'movement_type' => 'reserve',
        'qty_delta' => 0,
        'reserved_delta' => 5,
        'on_hand_after' => 50,
        'reserved_after' => 5,
        'ref_type' => 'order',
        'ref_id' => $order->id,
    ]);

    return [$org, $item, $product];
}

test('audit flags cache drift and leaves matching entities out', function () {
    [$org, $item] = auditMismatchFixture();

    $report = app(ReservationAudit::class)->run($org->id);

    expect($report['items']['scanned'])->toBe(1);
    expect($report['items']['mismatched'])->toBe(1);
    expect($report['products']['scanned'])->toBe(1);
    expect($report['products']['mismatched'])->toBe(0);

    $row = $report['items']['rows'][0];
    expect($row['id'])->toBe($item->id);
    expect($row['cache'])->toBe(10.0);
    expect($row['active_rows'])->toBe(8.0);
    expect($row['ledger'])->toBe(8.0);
    expect($row['cache_minus_rows'])->toBe(2.0);
    expect($row['cache_minus_ledger'])->toBe(2.0);
    expect($row['rows_minus_ledger'])->toBe(0.0);
    expect($row['flags'])->toBe(['cache≠rows', 'cache≠ledger']);
});

test('audit command writes json and csv without changing data', function () {
    [$org, $item] = auditMismatchFixture();

    $json = sys_get_temp_dir().'/reservation-audit-'.uniqid().'.json';
    $csv = sys_get_temp_dir().'/reservation-audit-'.uniqid().'.csv';

    $this->artisan('audit:reservations', [
        '--org' => $org->id,
        '--json' => $json,
        '--csv' => $csv,
    ])->assertSuccessful();

    expect(file_exists($json))->toBeTrue();
    expect(file_exists($csv))->toBeTrue();

    $report = json_decode((string) file_get_contents($json), true);
    expect($report['items']['mismatched'])->toBe(1);
    expect($report['products']['mismatched'])->toBe(0);

    $csvContents = (string) file_get_contents($csv);
    expect($csvContents)->toContain('Audit Item');
    expect($csvContents)->toContain('cache≠rows');

    expect(Item::find($item->id)->quantity_reserved)->toBe(10.0);

    @unlink($json);
    @unlink($csv);
});

test('audit detail shows the reserving orders and ledger breakdown', function () {
    [$org, $item] = auditMismatchFixture();

    $this->artisan('audit:reservations', [
        '--detail' => 'item:'.$item->id,
        '--org' => $org->id,
    ])
        ->expectsOutputToContain('Audit Item')
        ->assertSuccessful();
});
