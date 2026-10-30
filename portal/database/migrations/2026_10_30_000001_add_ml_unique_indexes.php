<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the missing unique keys to the existing ML prediction tables.
 *
 * sp_demand_forecasts.model_version was nullable; a nullable column in a
 * unique key defeats uniqueness (NULL != NULL), so backfill it and make it
 * NOT NULL before adding the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp_demand_forecasts')) {
            DB::table('sp_demand_forecasts')
                ->whereNull('model_version')
                ->update(['model_version' => 'baseline-v1']);

            $driver = DB::getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE sp_demand_forecasts ALTER COLUMN model_version SET NOT NULL');
            } elseif ($driver === 'mysql') {
                DB::statement('ALTER TABLE sp_demand_forecasts MODIFY model_version VARCHAR(64) NOT NULL');
            }

            if (! Schema::hasIndex('sp_demand_forecasts', 'sp_forecasts_unique')) {
                Schema::table('sp_demand_forecasts', function (Blueprint $table) {
                    $table->unique(
                        ['organization_id', 'entity_type', 'entity_id', 'forecast_date', 'model_version'],
                        'sp_forecasts_unique'
                    );
                });
            }
        }

        if (Schema::hasTable('sp_stockout_risks') && ! Schema::hasIndex('sp_stockout_risks', 'sp_stockout_unique')) {
            Schema::table('sp_stockout_risks', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'entity_type', 'entity_id'],
                    'sp_stockout_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('sp_demand_forecasts', 'sp_forecasts_unique')) {
            Schema::table('sp_demand_forecasts', function (Blueprint $table) {
                $table->dropUnique('sp_forecasts_unique');
            });
        }

        if (Schema::hasIndex('sp_stockout_risks', 'sp_stockout_unique')) {
            Schema::table('sp_stockout_risks', function (Blueprint $table) {
                $table->dropUnique('sp_stockout_unique');
            });
        }
    }
};
