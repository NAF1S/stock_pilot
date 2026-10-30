<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce order IDs restart per store. The old unique key
 * (organization_id, woocommerce_id) would silently drop the second store's
 * order #100; scope it by store instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sp_orders')) {
            return;
        }

        if (Schema::hasIndex('sp_orders', 'sp_orders_organization_id_woocommerce_id_unique')) {
            Schema::table('sp_orders', function (Blueprint $table) {
                $table->dropUnique('sp_orders_organization_id_woocommerce_id_unique');
            });
        }

        if (! Schema::hasIndex('sp_orders', 'sp_orders_org_store_woo_unique')) {
            Schema::table('sp_orders', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'store_id', 'woocommerce_id'],
                    'sp_orders_org_store_woo_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('sp_orders', 'sp_orders_org_store_woo_unique')) {
            Schema::table('sp_orders', function (Blueprint $table) {
                $table->dropUnique('sp_orders_org_store_woo_unique');
            });
        }

        if (! Schema::hasIndex('sp_orders', 'sp_orders_organization_id_woocommerce_id_unique')) {
            Schema::table('sp_orders', function (Blueprint $table) {
                $table->unique(
                    ['organization_id', 'woocommerce_id'],
                    'sp_orders_organization_id_woocommerce_id_unique'
                );
            });
        }
    }
};
