<?php

use App\Models\BomLine;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function eventTimeFixture(): array
{
    $org = Organization::create(['name' => 'Event Time Org']);

    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Event Time Store',
        'store_url' => 'https://time.example.com',
        'api_key' => 'sp_timekey',
        'api_secret' => 'timesecrettimesecrettimesecret',
    ]);

    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Leather',
        'unit' => 'pcs',
        'cost_price' => 10,
        'quantity_on_hand' => 100,
        'low_stock_threshold' => 5,
    ]);

    $product = Product::create([
        'organization_id' => $org->id,
        'name' => 'Belt Kit',
        'sku' => 'KIT-TIME',
        'source' => 'manual',
        'quantity_on_hand' => 50,
    ]);

    BomLine::create([
        'organization_id' => $org->id,
        'product_id' => $product->id,
        'item_id' => $item->id,
        'quantity' => 2,
    ]);

    return [$org, $store, $item, $product];
}

function eventTimePayload(int $id, string $status, Carbon $at): array
{
    return [
        'id' => $id,
        'number' => (string) $id,
        'status' => $status,
        'date_created' => $at->toIso8601String(),
        'date_modified' => $at->toIso8601String(),
        'customer' => ['name' => 'Time Tester', 'email' => 'time@example.com'],
        'currency' => 'BDT',
        'totals' => ['subtotal' => 100, 'total' => 100],
        'items' => [
            [
                'id' => 1,
                'product_id' => 1,
                'name' => 'Belt Kit',
                'sku' => 'KIT-TIME',
                'quantity' => 1,
                'price' => 100,
                'line_total' => 100,
            ],
        ],
        'refunds' => [],
    ];
}

function eventTimeWebhook(array $orderData, Organization $org, StoreConnection $store, string $eventId): WebhookEvent
{
    return WebhookEvent::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_order_id' => $orderData['id'],
        'event_id' => $eventId,
        'nonce' => Str::random(32),
        'event_type' => 'order.sync',
        'wc_status' => $orderData['status'],
        'occurred_at' => isset($orderData['event']['occurred_at'])
            ? Carbon::parse($orderData['event']['occurred_at'])
            : null,
        'payload' => json_encode([
            'order' => $orderData,
            'event' => $orderData['event'] ?? [],
        ]),
        'status' => 'received',
    ]);
}

test('movements, order timestamps and status events use the event occurred_at', function () {
    [$org, $store] = eventTimeFixture();

    $placed = now()->subDays(10)->startOfSecond();
    $completed = now()->subDays(9)->startOfSecond();

    // 1) Order is placed (processing) — reserve.
    $first = eventTimePayload(1, 'processing', $placed);
    $first['event'] = ['occurred_at' => $placed->toIso8601String()];
    $firstEvent = eventTimeWebhook($first, $org, $store, 'evt-time-1a');
    app(InventoryEngine::class)->processOrderEvent($first, $store, $firstEvent);

    // 2) Order is completed — confirm; this is the status transition.
    $second = eventTimePayload(1, 'completed', $completed);
    $second['event'] = [
        'occurred_at' => $completed->toIso8601String(),
        'captured_at' => now()->toIso8601String(),
        'reason' => 'status:processing->completed',
    ];
    $secondEvent = eventTimeWebhook($second, $org, $store, 'evt-time-1b');
    app(InventoryEngine::class)->processOrderEvent($second, $store, $secondEvent);

    $movement = ItemMovement::where('ref_type', 'order')
        ->where('movement_type', 'confirm')
        ->firstOrFail();

    expect($movement->occurred_at->toIso8601String())->toBe($completed->toIso8601String());
    // created_at is still the record time, not the event time.
    expect($movement->created_at->toDateString())->not->toBe($completed->toDateString());

    $order = Order::where('woocommerce_id', 1)->firstOrFail();
    expect($order->confirmed_at->toIso8601String())->toBe($completed->toIso8601String());
    expect($order->order_created_at->toIso8601String())->toBe($placed->toIso8601String());

    $status = OrderStatusEvent::where('order_id', $order->id)->firstOrFail();
    expect($status->occurred_at->toIso8601String())->toBe($completed->toIso8601String());
});

test('falls back to the order date_modified when no envelope is present', function () {
    [$org, $store] = eventTimeFixture();

    $modified = now()->subDays(5)->startOfSecond();
    $data = eventTimePayload(2, 'completed', $modified);

    $event = eventTimeWebhook($data, $org, $store, 'evt-time-2');

    app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    $movement = ItemMovement::where('ref_type', 'order')
        ->where('movement_type', 'confirm')
        ->firstOrFail();

    expect($movement->occurred_at->toIso8601String())->toBe($modified->toIso8601String());
});

test('rejects future event times and uses the current time instead', function () {
    [$org, $store] = eventTimeFixture();

    $future = now()->addDay()->startOfSecond();
    $data = eventTimePayload(3, 'completed', $future);
    $data['event'] = ['occurred_at' => $future->toIso8601String()];

    $event = eventTimeWebhook($data, $org, $store, 'evt-time-3');

    app(InventoryEngine::class)->processOrderEvent($data, $store, $event);

    $movement = ItemMovement::where('ref_type', 'order')
        ->where('movement_type', 'confirm')
        ->firstOrFail();

    expect($movement->occurred_at->between(now()->subMinutes(2), now()->addMinutes(2)))->toBeTrue();
});

test('returns use the refund date_created as their event time', function () {
    [$org, $store] = eventTimeFixture();

    $placed = now()->subDays(20)->startOfSecond();
    $refunded = now()->subDays(3)->startOfSecond();

    $first = eventTimePayload(4, 'completed', $placed);
    $first['event'] = ['occurred_at' => $placed->toIso8601String()];
    $firstEvent = eventTimeWebhook($first, $org, $store, 'evt-time-4a');
    app(InventoryEngine::class)->processOrderEvent($first, $store, $firstEvent);

    $second = eventTimePayload(4, 'refunded', $refunded);
    $second['refunds'] = [
        [
            'id' => 99,
            'reason' => 'customer return',
            'total' => 100,
            'date_created' => $refunded->toIso8601String(),
            'items' => [['item_id' => 1, 'quantity' => 1, 'total' => 100]],
        ],
    ];
    $second['event'] = ['occurred_at' => $refunded->toIso8601String()];
    $secondEvent = eventTimeWebhook($second, $org, $store, 'evt-time-4b');

    app(InventoryEngine::class)->processOrderEvent($second, $store, $secondEvent);

    $return = ItemMovement::where('movement_type', 'return')->orderByDesc('id')->firstOrFail();
    expect($return->occurred_at->toIso8601String())->toBe($refunded->toIso8601String());
});

test('repair command is dry-run by default and audits applied changes', function () {
    [$org, $store, $item] = eventTimeFixture();

    $order = Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => 777,
        'woocommerce_number' => '777',
        'status' => 'completed',
        'reservation_state' => 'confirmed',
        'order_created_at' => now()->subDays(10),
        'order_updated_at' => now()->subDays(9),
    ]);

    $movement = ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'movement_type' => 'confirm',
        'qty_delta' => -1,
        'reserved_delta' => 0,
        'on_hand_after' => 99,
        'reserved_after' => 0,
        'ref_type' => 'order',
        'ref_id' => $order->id,
    ]);

    expect($movement->occurred_at)->toBeNull();

    $this->artisan('ml:repair-timeline')->assertSuccessful();
    expect($movement->fresh()->occurred_at)->toBeNull();

    $this->artisan('ml:repair-timeline', ['--apply' => true])->assertSuccessful();

    expect($movement->fresh()->occurred_at->toDateTimeString())
        ->toBe($order->order_updated_at->toDateTimeString());

    expect(DB::table('sp_timeline_repairs')->count())->toBe(1);
    expect(DB::table('sp_timeline_repairs')->first()->movement_id)->toBe($movement->id);
});
