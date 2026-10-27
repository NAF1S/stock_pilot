<?php

namespace App\Jobs\Processors;

use App\Models\Product;
use App\Models\StoreConnection;
use App\Models\TrafficDaily;
use App\Models\TrafficHourly;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Persists a plugin traffic.rollup bucket.
 *
 * The plugin sends a cumulative snapshot for one (product, UTC hour) bucket.
 * The hourly row is UPSERTED (not incremented), which makes retries and
 * duplicate deliveries idempotent. The daily row is then recomputed from all
 * hourly rows for the plugin-supplied local date, so a late/corrected bucket
 * can never double-count.
 */
class TrafficProcessor
{
    public const SOURCE = 'plugin_realtime';

    /** @return array{action: string, reason?: string, product_id?: int, traffic_date?: string} */
    public function process(array $data, StoreConnection $store): array
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $bucketRaw = (string) ($data['bucket_hour'] ?? '');

        if ($productId <= 0 || $bucketRaw === '') {
            return ['action' => 'noop', 'reason' => 'Traffic rollup is missing product_id or bucket_hour.'];
        }

        try {
            $bucket = Carbon::parse($bucketRaw)->utc();
        } catch (\Throwable) {
            return ['action' => 'noop', 'reason' => 'Traffic rollup has an invalid bucket_hour.'];
        }

        $now = now();

        if ($bucket->greaterThan($now->copy()->addMinutes(5)) || $bucket->lessThan($now->copy()->subDays(400))) {
            return ['action' => 'noop', 'reason' => 'Traffic bucket is outside the accepted time window.'];
        }

        $trafficDate = $this->trafficDate($data['bucket_date'] ?? null, $bucket);

        $product = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('woo_product_id', $productId)
            ->first();

        if (! $product) {
            Log::warning('Traffic rollup for an unmapped WooCommerce product', [
                'store_id' => $store->id,
                'woo_product_id' => $productId,
            ]);

            return [
                'action' => 'noop',
                'reason' => "Unmapped WooCommerce product {$productId} for store {$store->id}.",
            ];
        }

        $counts = $this->counts($data);

        TrafficHourly::upsert(
            [[
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'product_id' => $product->id,
                'bucket_hour' => $bucket,
                'traffic_date' => $trafficDate,
                'source' => self::SOURCE,
                ...$counts,
            ]],
            ['organization_id', 'store_id', 'product_id', 'bucket_hour', 'source'],
            ['traffic_date', 'page_views', 'add_to_cart', 'cart_removals', 'sessions', 'unique_visitors', 'updated_at'],
        );

        $totals = TrafficHourly::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->where('traffic_date', $trafficDate)
            ->where('source', self::SOURCE)
            ->selectRaw('coalesce(sum(page_views), 0) as page_views')
            ->selectRaw('coalesce(sum(add_to_cart), 0) as add_to_cart')
            ->selectRaw('coalesce(sum(cart_removals), 0) as cart_removals')
            ->selectRaw('coalesce(sum(sessions), 0) as sessions')
            ->selectRaw('coalesce(sum(unique_visitors), 0) as unique_visitors')
            ->first();

        TrafficDaily::upsert(
            [[
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'product_id' => $product->id,
                'woo_product_id' => $product->woo_product_id,
                'traffic_date' => $trafficDate,
                'page_views' => (int) ($totals->page_views ?? 0),
                'add_to_cart' => (int) ($totals->add_to_cart ?? 0),
                'cart_removals' => (int) ($totals->cart_removals ?? 0),
                'sessions' => (int) ($totals->sessions ?? 0),
                'unique_visitors' => (int) ($totals->unique_visitors ?? 0),
                'source' => self::SOURCE,
            ]],
            ['organization_id', 'store_id', 'product_id', 'traffic_date', 'source'],
            ['woo_product_id', 'page_views', 'add_to_cart', 'cart_removals', 'sessions', 'unique_visitors', 'updated_at'],
        );

        return [
            'action' => 'processed',
            'product_id' => $product->id,
            'traffic_date' => $trafficDate,
        ];
    }

    /** @return array<string, int> */
    private function counts(array $data): array
    {
        $counts = [];

        foreach (['page_views', 'add_to_cart', 'cart_removals', 'sessions', 'unique_visitors'] as $key) {
            $counts[$key] = max(0, (int) ($data[$key] ?? 0));
        }

        return $counts;
    }

    private function trafficDate(mixed $rawDate, Carbon $bucket): string
    {
        if (is_string($rawDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $rawDate)->toDateString();
            } catch (\Throwable) {
                // Fall through to the UTC bucket date.
            }
        }

        return $bucket->toDateString();
    }
}
