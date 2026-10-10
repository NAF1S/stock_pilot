<?php

namespace App\Console\Commands;

use App\Models\DemandForecast;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RestockRecommendation;
use App\Models\StockoutRisk;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateForecasts extends Command
{
    protected $signature = 'ml:forecast {--org= : Specific organization ID}';
    protected $description = 'Compute baseline inventory burn rates, stockout risks, and demand forecasts';

    public function handle(): int
    {
        $orgId = $this->option('org');
        $orgs = $orgId ? Organization::where('id', $orgId)->get() : Organization::all();

        $this->info("Starting forecast & risk pipeline for {$orgs->count()} organization(s)...");

        $totalRisks = 0;
        $totalRecs = 0;
        $totalForecasts = 0;

        foreach ($orgs as $org) {
            $this->line("Processing: {$org->name} (ID: {$org->id})");

            // Evaluate Items (Raw materials)
            $items = Item::withoutGlobalScopes()
                ->where('organization_id', $org->id)
                ->whereNull('deleted_at')
                ->get();

            foreach ($items as $item) {
                $stock = (float) $item->quantity_on_hand;
                $threshold = (float) $item->low_stock_threshold;

                // 30-day movement consumption (outward flow where qty_delta < 0)
                $out30 = (float) DB::table('sp_item_movements')
                    ->where('organization_id', $org->id)
                    ->where('item_id', $item->id)
                    ->where('qty_delta', '<', 0)
                    ->where('created_at', '>=', Carbon::now()->subDays(30))
                    ->sum(DB::raw('ABS(qty_delta)'));

                $dailyBurn = round($out30 / 30.0, 4);
                $daysLeft = $dailyBurn > 0 ? round($stock / $dailyBurn, 2) : null;
                $stockoutDate = $daysLeft !== null ? Carbon::now()->addDays($daysLeft)->toDateString() : null;

                if ($stock <= 0 || ($daysLeft !== null && $daysLeft <= 3.0)) {
                    $riskLevel = 'critical';
                } elseif (($daysLeft !== null && $daysLeft <= 7.0) || ($threshold > 0 && $stock <= $threshold)) {
                    $riskLevel = 'warning';
                } else {
                    $riskLevel = 'healthy';
                }

                StockoutRisk::withoutGlobalScopes()->updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'entity_type' => 'item',
                        'entity_id' => $item->id,
                    ],
                    [
                        'current_stock' => $stock,
                        'daily_burn_rate' => $dailyBurn,
                        'days_until_stockout' => $daysLeft,
                        'risk_level' => $riskLevel,
                        'estimated_stockout_date' => $stockoutDate,
                        'model_version' => 'baseline-v1',
                        'calculated_at' => Carbon::now(),
                    ]
                );
                $totalRisks++;

                // Restock Recommendation
                if (in_array($riskLevel, ['critical', 'warning'])) {
                    $suggestedQty = max(round($dailyBurn * 30.0, 2), 10.0);
                    $urgency = $riskLevel === 'critical' ? 'high' : 'medium';
                    $reason = "Inventory ({$stock} {$item->unit}) projected to exhaust in " . ($daysLeft ?? 0) . " days based on 30d burn rate of {$dailyBurn}/day.";

                    RestockRecommendation::withoutGlobalScopes()->updateOrCreate(
                        [
                            'organization_id' => $org->id,
                            'entity_type' => 'item',
                            'entity_id' => $item->id,
                            'status' => 'pending',
                        ],
                        [
                            'recommended_reorder_qty' => $suggestedQty,
                            'urgency' => $urgency,
                            'reasoning' => $reason,
                            'model_version' => 'baseline-v1',
                        ]
                    );
                    $totalRecs++;
                }

                // 14-day daily forecast projections
                for ($d = 1; $d <= 14; $d++) {
                    $fDate = Carbon::now()->addDays($d)->toDateString();
                    DemandForecast::withoutGlobalScopes()->updateOrCreate(
                        [
                            'organization_id' => $org->id,
                            'entity_type' => 'item',
                            'entity_id' => $item->id,
                            'forecast_date' => $fDate,
                        ],
                        [
                            'predicted_quantity' => $dailyBurn,
                            'lower_bound' => round($dailyBurn * 0.8, 4),
                            'upper_bound' => round($dailyBurn * 1.2, 4),
                            'model_version' => 'baseline-v1',
                        ]
                    );
                    $totalForecasts++;
                }
            }
        }

        $this->info("Completed! Risks: {$totalRisks}, Recommendations: {$totalRecs}, Daily Forecasts: {$totalForecasts}");

        return Command::SUCCESS;
    }
}
