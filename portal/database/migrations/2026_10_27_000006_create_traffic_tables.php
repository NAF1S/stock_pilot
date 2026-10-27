<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traffic storage for both sources:
 *   - plugin_realtime: hourly rollups rolled into daily product totals;
 *   - jetpack: historical daily product/site/referrer exports (Phase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sp_traffic_daily')) {
            Schema::create('sp_traffic_daily', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('sp_store_connections')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('sp_products')->cascadeOnDelete();
                $table->unsignedBigInteger('woo_product_id')->nullable();
                $table->date('traffic_date');
                $table->unsignedInteger('page_views')->default(0);
                $table->unsignedInteger('add_to_cart')->default(0);
                $table->unsignedInteger('cart_removals')->default(0);
                $table->unsignedInteger('sessions')->default(0);
                $table->unsignedInteger('unique_visitors')->default(0);
                $table->string('source', 16)->default('plugin_realtime');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'store_id', 'product_id', 'traffic_date', 'source'],
                    'sp_traffic_daily_unique'
                );
                $table->index(['organization_id', 'traffic_date']);
            });
        }

        if (! Schema::hasTable('sp_traffic_hourly')) {
            Schema::create('sp_traffic_hourly', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('sp_store_connections')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('sp_products')->cascadeOnDelete();
                $table->timestamp('bucket_hour');
                $table->date('traffic_date');
                $table->unsignedInteger('page_views')->default(0);
                $table->unsignedInteger('add_to_cart')->default(0);
                $table->unsignedInteger('cart_removals')->default(0);
                $table->unsignedInteger('sessions')->default(0);
                $table->unsignedInteger('unique_visitors')->default(0);
                $table->string('source', 16)->default('plugin_realtime');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'store_id', 'product_id', 'bucket_hour', 'source'],
                    'sp_traffic_hourly_unique'
                );
                $table->index(['organization_id', 'traffic_date']);
            });
        }

        if (! Schema::hasTable('sp_traffic_daily_org')) {
            Schema::create('sp_traffic_daily_org', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('sp_store_connections')->cascadeOnDelete();
                $table->date('traffic_date');
                $table->unsignedInteger('page_views')->default(0);
                $table->unsignedInteger('unique_visitors')->default(0);
                $table->unsignedInteger('cart_page_views')->default(0);
                $table->unsignedInteger('checkout_page_views')->default(0);
                $table->string('source', 16)->default('jetpack');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'store_id', 'traffic_date', 'source'],
                    'sp_traffic_daily_org_unique'
                );
                $table->index(['organization_id', 'traffic_date']);
            });
        }

        if (! Schema::hasTable('sp_traffic_referrers_daily')) {
            Schema::create('sp_traffic_referrers_daily', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('sp_store_connections')->cascadeOnDelete();
                $table->date('traffic_date');
                $table->string('referrer_group', 128);
                $table->unsignedInteger('page_views')->default(0);
                $table->string('source', 16)->default('jetpack');
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'store_id', 'traffic_date', 'referrer_group', 'source'],
                    'sp_traffic_referrers_unique'
                );
                $table->index(['organization_id', 'traffic_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_traffic_referrers_daily');
        Schema::dropIfExists('sp_traffic_daily_org');
        Schema::dropIfExists('sp_traffic_hourly');
        Schema::dropIfExists('sp_traffic_daily');
    }
};
