<?php

use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\TrafficDaily;
use App\Models\TrafficHourly;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function trafficFixture(): array
{
    $org = Organization::create(['name' => 'Traffic Org']);

    $store = StoreConnection::create([
        'organization_id' => $org->id,
        'name' => 'Traffic Store',
        'store_url' => 'https://traffic.example.com',
        'api_key' => 'sp_traffic_key',
        'api_secret' => 'trafficsecretrafficsecretrafficsecret',
    ]);

    $product = Product::create([
        'organization_id' => $org->id,
        'store_id' => $store->id,
        'woo_product_id' => 5001,
        'source' => 'manual',
        'name' => 'Traffic Product',
        'sku' => 'TR-1',
        'quantity_on_hand' => 10,
    ]);

    return [$org, $store, $product];
}

function trafficBody(int $wooProductId, Carbon $bucket, array $overrides = []): array
{
    return [
        'traffic' => array_merge([
            'product_id' => $wooProductId,
            'bucket_hour' => $bucket->copy()->utc()->toIso8601String(),
            'bucket_date' => $bucket->copy()->utc()->toDateString(),
            'page_views' => 3,
            'add_to_cart' => 1,
            'cart_removals' => 0,
            'sessions' => 2,
            'unique_visitors' => 2,
        ], $overrides),
        'event' => [
            'occurred_at' => $bucket->copy()->utc()->toIso8601String(),
            'captured_at' => now()->toIso8601String(),
            'reason' => 'traffic_rollup',
        ],
    ];
}

function postTraffic($test, string $apiKey, string $secret, string $eventId, array $body)
{
    $json = json_encode($body);
    $timestamp = (string) time();
    $nonce = Str::random(32);
    $signature = base64_encode(hash_hmac('sha256', "{$timestamp}:{$nonce}:{$eventId}:{$json}", $secret, true));

    return $test->call(
        'POST',
        '/api/webhooks/traffic',
        [],
        [],
        [],
        [
            'HTTP_X_API_KEY' => $apiKey,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_EVENT_ID' => $eventId,
            'HTTP_X_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $json,
    );
}

test('a signed traffic rollup is stored in hourly and daily tables', function () {
    [$org, $store, $product] = trafficFixture();
    $bucket = now()->subHours(2)->startOfHour();

    $response = postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-test-1', trafficBody(5001, $bucket));
    $response->assertOk();

    $event = WebhookEvent::where('event_id', 'traffic-test-1')->first();
    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('traffic.rollup');
    expect($event->status)->toBe('processed');
    expect($event->occurred_at)->not->toBeNull();

    $hourly = TrafficHourly::where('organization_id', $org->id)->where('product_id', $product->id)->first();
    expect($hourly)->not->toBeNull();
    expect($hourly->page_views)->toBe(3);
    expect($hourly->add_to_cart)->toBe(1);
    expect($hourly->sessions)->toBe(2);
    expect($hourly->unique_visitors)->toBe(2);
    expect($hourly->source)->toBe('plugin_realtime');

    $daily = TrafficDaily::where('organization_id', $org->id)->where('product_id', $product->id)->first();
    expect($daily)->not->toBeNull();
    expect($daily->page_views)->toBe(3);
    expect($daily->add_to_cart)->toBe(1);
});

test('a newer cumulative bucket snapshot replaces the hourly count instead of adding', function () {
    [$org, $store, $product] = trafficFixture();
    $bucket = now()->subHours(2)->startOfHour();

    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-test-a', trafficBody(5001, $bucket))->assertOk();
    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-test-b', trafficBody(5001, $bucket, [
        'page_views' => 10,
        'add_to_cart' => 2,
        'sessions' => 5,
        'unique_visitors' => 4,
    ]))->assertOk();

    $hourly = TrafficHourly::where('organization_id', $org->id)->where('product_id', $product->id)->first();
    expect($hourly->page_views)->toBe(10);
    expect($hourly->add_to_cart)->toBe(2);
    expect($hourly->unique_visitors)->toBe(4);

    $daily = TrafficDaily::where('organization_id', $org->id)->where('product_id', $product->id)->first();
    expect($daily->page_views)->toBe(10);
    expect($daily->add_to_cart)->toBe(2);

    expect(WebhookEvent::where('organization_id', $org->id)->count())->toBe(2);
});

test('a duplicate traffic event id is rejected and does not double count', function () {
    [$org, $store, $product] = trafficFixture();
    $bucket = now()->subHours(2)->startOfHour();
    $body = trafficBody(5001, $bucket);

    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-dupe', $body)->assertOk();
    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-dupe', $body)->assertStatus(409);

    $hourly = TrafficHourly::where('organization_id', $org->id)->where('product_id', $product->id)->first();
    expect($hourly->page_views)->toBe(3);
    expect(WebhookEvent::where('organization_id', $org->id)->count())->toBe(1);
});

test('an unmapped product is stored as a noop without traffic rows', function () {
    [$org, $store, $product] = trafficFixture();
    $bucket = now()->subHours(2)->startOfHour();

    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-missing', trafficBody(999999, $bucket))->assertOk();

    $event = WebhookEvent::where('event_id', 'traffic-missing')->first();
    expect($event->status)->toBe('noop');
    expect((string) $event->processing_error)->toContain('Unmapped');

    expect(TrafficHourly::where('organization_id', $org->id)->count())->toBe(0);
    expect(TrafficDaily::where('organization_id', $org->id)->count())->toBe(0);
});

test('multiple hourly buckets roll into one daily total', function () {
    [$org, $store, $product] = trafficFixture();
    $bucket = now()->subHours(3)->startOfHour();

    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-hour-1', trafficBody(5001, $bucket, [
        'page_views' => 3,
        'add_to_cart' => 1,
        'sessions' => 1,
        'unique_visitors' => 1,
    ]))->assertOk();

    $next = $bucket->copy()->addHour();
    postTraffic($this, 'sp_traffic_key', 'trafficsecretrafficsecretrafficsecret', 'traffic-hour-2', trafficBody(5001, $next, [
        'bucket_date' => $bucket->copy()->utc()->toDateString(),
        'page_views' => 4,
        'add_to_cart' => 2,
        'sessions' => 2,
        'unique_visitors' => 2,
    ]))->assertOk();

    expect(TrafficHourly::where('organization_id', $org->id)->count())->toBe(2);

    $daily = TrafficDaily::where('organization_id', $org->id)->where('product_id', $product->id)->first();
    expect($daily->page_views)->toBe(7);
    expect($daily->add_to_cart)->toBe(3);
    expect($daily->sessions)->toBe(3);
    expect($daily->unique_visitors)->toBe(3);
});
