<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunForecastJob;
use App\Models\ComputeRun;
use App\Models\DemandForecast;
use App\Models\Item;
use App\Models\Product;
use App\Models\RestockRecommendation;
use App\Models\StockoutRisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    /**
     * Executive AI Forecasting Summary for widgets and dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $criticalCount = StockoutRisk::where('risk_level', 'critical')->count();
        $warningCount = StockoutRisk::where('risk_level', 'warning')->count();
        $healthyCount = StockoutRisk::where('risk_level', 'healthy')->count();

        $urgentRecommendations = RestockRecommendation::where('status', 'pending')
            ->where('urgency', 'high')
            ->count();

        $topRisks = StockoutRisk::orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('days_until_stockout', 'asc')
            ->take(5)
            ->get()
            ->map(function ($risk) {
                return $this->enrichEntity($risk);
            });

        return response()->json([
            'counts' => [
                'critical' => $criticalCount,
                'warning' => $warningCount,
                'healthy' => $healthyCount,
                'urgent_recommendations' => $urgentRecommendations,
            ],
            'top_risks' => $topRisks,
            'last_updated' => StockoutRisk::max('calculated_at'),
        ]);
    }

    /**
     * List all stockout risk assessments.
     */
    public function risks(Request $request): JsonResponse
    {
        $query = StockoutRisk::query();

        if ($riskLevel = $request->input('risk_level')) {
            $query->where('risk_level', $riskLevel);
        }

        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        $risks = $query->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderBy('days_until_stockout', 'asc')
            ->get()
            ->map(fn ($r) => $this->enrichEntity($r));

        return response()->json(['risks' => $risks]);
    }

    /**
     * List smart restock recommendations.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');
        $recs = RestockRecommendation::where('status', $status)
            ->orderByRaw("CASE urgency WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => $this->enrichEntity($r));

        return response()->json(['recommendations' => $recs]);
    }

    /**
     * Accept or dismiss a restock recommendation.
     */
    public function updateRecommendation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:accepted,dismissed,pending',
        ]);

        $rec = RestockRecommendation::findOrFail($id);
        $rec->update(['status' => $validated['status']]);

        return response()->json(['recommendation' => $this->enrichEntity($rec)]);
    }

    /**
     * Demand forecast series for an entity over time.
     */
    public function demand(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|in:item,product',
            'entity_id' => 'required|integer',
        ]);

        $forecasts = DemandForecast::where('entity_type', $request->input('entity_type'))
            ->where('entity_id', $request->input('entity_id'))
            ->orderBy('forecast_date', 'asc')
            ->get();

        return response()->json(['forecasts' => $forecasts]);
    }

    /**
     * Queue a Modal training run, or return the one already in flight.
     */
    public function compute(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = ComputeRun::where('organization_id', $user->organization_id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json(['run_id' => $existing->id, 'status' => $existing->status]);
        }

        $run = ComputeRun::create([
            'organization_id' => $user->organization_id,
            'status' => 'queued',
            'trigger_source' => 'manual',
            'triggered_by' => $user->id,
        ]);

        RunForecastJob::dispatch($run->id);

        return response()->json(['run_id' => $run->id, 'status' => 'queued'], 202);
    }

    /**
     * Progress for the "Compute now" button.
     */
    public function computeStatus(Request $request, ComputeRun $run): JsonResponse
    {
        abort_unless($run->organization_id === $request->user()->organization_id, 404);

        $progress = max(0, min(100, (int) $run->progress_pct));
        $elapsed = $run->started_at ? (int) max(0, $run->started_at->diffInSeconds(now())) : 0;
        $eta = $progress > 0 && $progress < 100
            ? (int) round($elapsed / $progress * (100 - $progress))
            : null;

        return response()->json([
            'id' => $run->id,
            'status' => $run->status,
            'trigger_source' => $run->trigger_source,
            'progress_pct' => $progress,
            'done_entities' => (int) $run->done_entities,
            'total_entities' => (int) $run->total_entities,
            'eta_seconds' => $eta,
            'error' => $run->error,
            'run_id' => $run->run_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]);
    }

    /**
     * Pipeline freshness: how current the features, forecasts and risks are.
     */
    public function pipeline(Request $request): JsonResponse
    {
        $org = $request->user()->organization_id;

        $features = \Illuminate\Support\Facades\DB::table('ml_daily_features')
            ->where('organization_id', $org)
            ->selectRaw('max(feature_date) as last_feature_date, count(*) as feature_rows')
            ->first();

        $forecasts = \Illuminate\Support\Facades\DB::table('sp_demand_forecasts')
            ->where('organization_id', $org)
            ->where('model_version', 'not like', 'baseline-%')
            ->selectRaw(
                'max(updated_at) as last_forecast_at, count(*) as forecast_rows,
                 count(*) filter (where forecast_date < current_date) as past_rows,
                 min(forecast_date) as first_forecast_date, max(forecast_date) as last_forecast_date'
            )
            ->first();

        $risks = \Illuminate\Support\Facades\DB::table('sp_stockout_risks')
            ->where('organization_id', $org)
            ->selectRaw('max(calculated_at) as last_risk_at, count(*) as risk_rows')
            ->first();

        $featureLag = $features?->last_feature_date
            ? (int) \Illuminate\Support\Carbon::parse($features->last_feature_date)->startOfDay()
                ->diffInDays(now()->startOfDay(), false)
            : null;
        $forecastAge = $forecasts?->last_forecast_at
            ? round(\Illuminate\Support\Carbon::parse($forecasts->last_forecast_at)->diffInMinutes(now()) / 60, 1)
            : null;
        $riskAge = $risks?->last_risk_at
            ? round(\Illuminate\Support\Carbon::parse($risks->last_risk_at)->diffInMinutes(now()) / 60, 1)
            : null;

        $problems = [];
        if ($featureLag === null) {
            $problems[] = 'no_feature_rows';
        } elseif ($featureLag > 2) {
            $problems[] = "features_stale_{$featureLag}d";
        }
        if ($forecastAge === null) {
            $problems[] = 'no_forecasts';
        } elseif ($forecastAge > 36) {
            $problems[] = "forecasts_stale_{$forecastAge}h";
        }
        if ($riskAge === null) {
            $problems[] = 'no_risks';
        } elseif ($riskAge > 36) {
            $problems[] = "risks_stale_{$riskAge}h";
        }
        if (($forecasts?->past_rows ?? 0) > 0) {
            $problems[] = "past_forecast_rows_{$forecasts->past_rows}";
        }

        return response()->json([
            'status' => $problems ? 'stale' : 'ok',
            'problems' => $problems,
            'last_feature_date' => $features?->last_feature_date,
            'feature_lag_days' => $featureLag,
            'feature_rows' => (int) ($features?->feature_rows ?? 0),
            'last_forecast_at' => $forecasts?->last_forecast_at,
            'forecast_age_hours' => $forecastAge,
            'forecast_rows' => (int) ($forecasts?->forecast_rows ?? 0),
            'past_forecast_rows' => (int) ($forecasts?->past_rows ?? 0),
            'first_forecast_date' => $forecasts?->first_forecast_date,
            'last_forecast_date' => $forecasts?->last_forecast_date,
            'last_risk_at' => $risks?->last_risk_at,
            'risk_age_hours' => $riskAge,
            'risk_rows' => (int) ($risks?->risk_rows ?? 0),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function enrichEntity($model): array
    {
        $data = $model->toArray();
        if ($model->entity_type === 'item') {
            $item = Item::find($model->entity_id);
            $data['entity_name'] = $item?->name ?? "Item #{$model->entity_id}";
            $data['entity_sku'] = $item?->sku;
            $data['entity_unit'] = $item?->unit ?? 'pcs';
        } else {
            $product = Product::find($model->entity_id);
            $data['entity_name'] = $product?->name ?? "Product #{$model->entity_id}";
            $data['entity_sku'] = $product?->sku;
            $data['entity_unit'] = 'units';
        }
        return $data;
    }
}
