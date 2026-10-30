<?php
/**
 * Phase 5 writer: upsert daily feature rows from a JSON file.
 *
 * Usage: php phase5_write.php <features.json>
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$path = $argv[1] ?? null;

if (!$path || !is_file($path)) {
    fwrite(STDERR, "features JSON file not found\n");
    exit(1);
}

$rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

foreach ($rows as &$row) {
    if (isset($row['traffic_sources']) && is_array($row['traffic_sources'])) {
        $row['traffic_sources'] = json_encode($row['traffic_sources']);
    }
}
unset($row);

$unique = ['organization_id', 'store_id', 'entity_type', 'entity_id', 'feature_date'];
$update = [
    'units_consumed', 'units_returned', 'units_reserved', 'order_count', 'revenue',
    'avg_order_value', 'stock_on_hand', 'stock_reserved', 'stock_available',
    'page_views', 'add_to_cart', 'cart_removals', 'sessions', 'unique_visitors',
    'traffic_sources', 'ad_spend', 'ad_impressions', 'ad_clicks',
    'is_weekend', 'is_holiday', 'holiday_name', 'days_to_next_holiday',
    'is_payday', 'is_festival_period', 'festival_name',
];

foreach (array_chunk($rows, 500) as $chunk) {
    DB::table('ml_daily_features')->upsert($chunk, $unique, $update);
}

echo count($rows);
