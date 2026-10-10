<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
            $table->string('entity_type', 32); // 'item' or 'product'
            $table->unsignedBigInteger('entity_id');
            $table->date('forecast_date');
            $table->decimal('predicted_quantity', 14, 4)->default(0);
            $table->decimal('lower_bound', 14, 4)->nullable();
            $table->decimal('upper_bound', 14, 4)->nullable();
            $table->string('model_version', 64)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'entity_id', 'forecast_date'], 'sp_forecasts_lookup_idx');
        });

        Schema::create('sp_stockout_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
            $table->string('entity_type', 32); // 'item' or 'product'
            $table->unsignedBigInteger('entity_id');
            $table->decimal('current_stock', 14, 4)->default(0);
            $table->decimal('daily_burn_rate', 14, 4)->default(0);
            $table->decimal('days_until_stockout', 10, 2)->nullable();
            $table->string('risk_level', 32)->default('healthy'); // 'critical', 'warning', 'healthy'
            $table->date('estimated_stockout_date')->nullable();
            $table->string('model_version', 64)->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->index(['organization_id', 'risk_level']);
            $table->index(['organization_id', 'entity_type', 'entity_id'], 'sp_stockout_lookup_idx');
        });

        Schema::create('sp_restock_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
            $table->string('entity_type', 32); // 'item' or 'product'
            $table->unsignedBigInteger('entity_id');
            $table->decimal('recommended_reorder_qty', 14, 4)->default(0);
            $table->string('urgency', 32)->default('medium'); // 'high', 'medium', 'low'
            $table->text('reasoning')->nullable();
            $table->string('status', 32)->default('pending'); // 'pending', 'accepted', 'dismissed'
            $table->string('model_version', 64)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'urgency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_restock_recommendations');
        Schema::dropIfExists('sp_stockout_risks');
        Schema::dropIfExists('sp_demand_forecasts');
    }
};
