<?php
/**
 * Phase 6 loader: emit ml_daily_features rows for model selection/backtesting.
 *
 * Usage: php phase6_load_features.php <org_id> <store_id>
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$org = (int) ($argv[1] ?? 1);
$store = (int) ($argv[2] ?? 1);

$rows = DB::table('ml_daily_features')
    ->where('organization_id', $org)
    ->where('store_id', $store)
    ->orderBy('entity_type')
    ->orderBy('entity_id')
    ->orderBy('feature_date')
    ->get();

echo json_encode($rows, JSON_THROW_ON_ERROR);
