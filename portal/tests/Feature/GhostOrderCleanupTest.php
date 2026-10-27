<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\Organization;
use App\Models\StoreConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function ghostFixture(): array
{
    $org = Organization::create(['name' => 'Ghost Org']);

    $user = User::create([
        'organization_id' => $org->id,
        'name' => 'Ghost Admin',
        'email' => 'ghost@example.com',
        'password' => 'secret1234',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Ghost Store',
        'store_url' => 'https://ghost.example.com',
        'api_key' => 'sp_ghostkey',
        'api_secret' => 'ghostsecretghostsecretghostsecret',
    ]);

    return [$org, $user, $store];
}

function ghostScenario(Organization $org, StoreConnection $store, int $wooId = 9501): array
{
    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Ghost Packaging',
        'sku' => 'GHOST-1',
        'unit' => 'pcs',
        'quantity_on_hand' => 100,
        'quantity_reserved' => 5,
    ]);

    $order = Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => $wooId,
        'woocommerce_number' => (string) $wooId,
        'status' => 'on-hold',
        'reservation_state' => 'reserved',
        'customer_name' => 'Ghost Customer',
        'order_created_at' => now()->subDays(30),
        'payload' => [
            'id' => $wooId,
            'number' => (string) $wooId,
            'status' => 'on-hold',
            'date_created' => now()->subDays(30)->toIso8601String(),
            'date_modified' => now()->subDays(30)->toIso8601String(),
            'items' => [
                ['id' => 1, 'product_id' => null, 'name' => 'Ghost Packaging', 'quantity' => 1],
            ],
            'refunds' => [],
        ],
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'woocommerce_item_id' => 1,
        'product_name' => 'Ghost Packaging',
        'quantity' => 1,
    ]);

    $component = OrderItemComponent::create([
        'organization_id' => $org->id,
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'item_id' => $item->id,
        'quantity_per_unit' => 5,
        'order_quantity' => 1,
        'total_quantity' => 5,
        'reserved_quantity' => 5,
        'status' => 'reserved',
        'reserved_at' => now()->subDays(30),
    ]);

    return [$item, $order, $component];
}

test('ghost release command is dry-run by default', function () {
    [$org, , $store] = ghostFixture();
    [$item, $order] = ghostScenario($org, $store);

    $this->artisan('orders:release-ghosts', ['--org' => $org->id])->assertSuccessful();

    expect($item->fresh()->quantity_reserved)->toBe(5.0);
    expect(Order::find($order->id))->not->toBeNull();
    expect(OrderItemComponent::where('order_id', $order->id)->where('reserved_quantity', '>', 0)->count())->toBe(1);
    expect(ItemMovement::where('movement_type', 'release')->count())->toBe(0);
});

test('ghost release command releases stock, keeps the ledger and can delete the order', function () {
    [$org, , $store] = ghostFixture();
    [$item, $order] = ghostScenario($org, $store);

    $this->artisan('orders:release-ghosts', [
        '--org' => $org->id,
        '--apply' => true,
        '--delete' => true,
    ])->assertSuccessful();

    expect($item->fresh()->quantity_reserved)->toBe(0.0);
    expect(Order::find($order->id))->toBeNull();
    expect(OrderItemComponent::where('order_id', $order->id)->where('reserved_quantity', '>', 0)->count())->toBe(0);

    // Deleting the order must not delete the append-only ledger.
    $release = ItemMovement::where('movement_type', 'release')
        ->where('ref_type', 'order')
        ->where('ref_id', $order->id)
        ->first();

    expect($release)->not->toBeNull();
    expect((float) $release->reserved_delta)->toBe(-5.0);
    expect($release->occurred_at)->not->toBeNull();

    expect(WebhookEvent::where('event_id', 'like', 'ghost-release-%')->where('status', 'processed')->count())->toBe(1);
});

test('delete signal for an unknown order does not create a phantom order', function () {
    [$org, , $store] = ghostFixture();

    $event = WebhookEvent::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_order_id' => 9999,
        'event_id' => 'del-unknown-1',
        'nonce' => Str::random(32),
        'event_type' => 'order.sync',
        'wc_status' => 'cancelled',
        'payload' => json_encode(['order' => ['id' => 9999, 'status' => 'cancelled']]),
        'status' => 'received',
    ]);

    (new ProcessWebhookEvent($event->id))->handle();

    expect(Order::where('woocommerce_id', 9999)->count())->toBe(0);
    expect($event->fresh()->status)->toBe('noop');
});

test('delete signal releases the existing reservation and preserves the stored payload', function () {
    [$org, , $store] = ghostFixture();
    [$item, $order, $component] = ghostScenario($org, $store);

    $occurredAt = now();

    $event = WebhookEvent::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_order_id' => $order->woocommerce_id,
        'event_id' => 'del-existing-1',
        'nonce' => Str::random(32),
        'event_type' => 'order.sync',
        'wc_status' => 'cancelled',
        'occurred_at' => $occurredAt,
        'payload' => json_encode([
            'order' => ['id' => $order->woocommerce_id, 'status' => 'cancelled'],
            'event' => [
                'occurred_at' => $occurredAt->toIso8601String(),
                'captured_at' => $occurredAt->toIso8601String(),
                'reason' => 'delete',
            ],
        ]),
        'status' => 'received',
    ]);

    (new ProcessWebhookEvent($event->id))->handle();

    $order->refresh();
    $component->refresh();

    expect($item->fresh()->quantity_reserved)->toBe(0.0);
    expect((float) $component->reserved_quantity)->toBe(0.0);
    expect($order->reservation_state)->toBe('released');
    expect($order->status)->toBe('cancelled');
    expect($event->fresh()->status)->toBe('processed');

    // The minimal delete payload must not overwrite the full stored order.
    expect($order->payload)->toBeArray();
    expect($order->payload['items'] ?? [])->not->toBeEmpty();

    $release = ItemMovement::where('movement_type', 'release')
        ->where('ref_type', 'order')
        ->where('ref_id', $order->id)
        ->first();

    expect($release)->not->toBeNull();
    expect((float) $release->reserved_delta)->toBe(-5.0);
    expect($release->occurred_at)->toBeInstanceOf(Carbon::class);
});
