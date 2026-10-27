<?php

use App\Models\Order;
use App\Models\Organization;
use App\Models\StoreConnection;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function closeWebhookFixture(): array
{
    $org = Organization::create(['name' => 'Webhook Close Org']);

    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Webhook Close Store',
        'store_url' => 'https://close.example.com',
        'api_key' => 'sp_closekey',
        'api_secret' => 'closesecretclosesecretclosesecret',
    ]);

    return [$org, $store];
}

function closeWebhookEvent(Organization $org, StoreConnection $store, string $eventId, ?int $wooId = null): WebhookEvent
{
    $event = WebhookEvent::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_order_id' => $wooId,
        'event_id' => $eventId,
        'nonce' => Str::random(32),
        'event_type' => 'order.sync',
        'wc_status' => 'cancelled',
        'payload' => '{}',
        'status' => 'received',
    ]);

    DB::table('sp_webhook_events')->where('id', $event->id)->update([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    return $event;
}

function closeWebhookOrder(Organization $org, StoreConnection $store, int $wooId, ?string $lastEvent = null): Order
{
    $order = Order::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woocommerce_id' => $wooId,
        'woocommerce_number' => (string) $wooId,
        'status' => 'cancelled',
        'reservation_state' => 'released',
        'order_created_at' => now()->subDays(2),
    ]);

    DB::table('sp_orders')->where('id', $order->id)->update([
        'last_webhook_event_id' => $lastEvent,
        'updated_at' => now(),
    ]);

    return $order;
}

function closeWebhookScenario(): array
{
    [$org, $store] = closeWebhookFixture();

    $reprocess = closeWebhookEvent($org, $store, 'reprocess-900-1', 900);
    closeWebhookOrder($org, $store, 900, 'ord-later-900');

    $delete = closeWebhookEvent($org, $store, 'delete-900-2');
    $pluginMissing = closeWebhookEvent($org, $store, 'ord-missing-1', 999);

    $latestEventId = 'ord-latest-1';
    $latest = closeWebhookEvent($org, $store, $latestEventId, 901);
    closeWebhookOrder($org, $store, 901, $latestEventId);

    $superseded = closeWebhookEvent($org, $store, 'ord-superseded-1', 902);
    closeWebhookOrder($org, $store, 902, 'ord-later-902');

    return [$org, $reprocess, $delete, $pluginMissing, $latest, $superseded];
}

test('webhook close-out is a dry-run by default', function () {
    [$org, $reprocess, $delete, $pluginMissing, $latest, $superseded] = closeWebhookScenario();

    $this->artisan('webhooks:close-superseded', ['--org' => $org->id])->assertSuccessful();

    foreach ([$reprocess, $delete, $pluginMissing, $latest, $superseded] as $event) {
        expect($event->fresh()->status)->toBe('received');
    }
});

test('webhook close-out closes artifacts without replaying and leaves the latest event alone', function () {
    [$org, $reprocess, $delete, $pluginMissing, $latest, $superseded] = closeWebhookScenario();

    $this->artisan('webhooks:close-superseded', [
        '--org' => $org->id,
        '--apply' => true,
    ])->assertSuccessful();

    $reprocess = $reprocess->fresh();
    expect($reprocess->status)->toBe('processed');
    expect($reprocess->processed_at)->not->toBeNull();
    expect((string) $reprocess->processing_error)->toContain('reprocess');

    expect($delete->fresh()->status)->toBe('noop');
    expect($pluginMissing->fresh()->status)->toBe('noop');
    expect($superseded->fresh()->status)->toBe('noop');

    // The latest received event for a live order must not be closed automatically.
    expect($latest->fresh()->status)->toBe('received');
    expect($latest->fresh()->processing_error)->toBeNull();
});
