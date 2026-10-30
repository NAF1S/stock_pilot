<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the WooCommerce product slug/permalink for traffic↔catalog joins.
 * The plugin already sends `slug` in every product payload; existing products
 * are backfilled from the stored JSON payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sp_products')) {
            return;
        }

        if (! Schema::hasColumn('sp_products', 'slug')) {
            Schema::table('sp_products', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('name');
                $table->index(['organization_id', 'slug'], 'sp_products_org_slug_idx');
            });
        }

        if (! Schema::hasColumn('sp_products', 'permalink')) {
            Schema::table('sp_products', function (Blueprint $table) {
                $table->string('permalink', 512)->nullable()->after('slug');
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "UPDATE sp_products
                 SET slug = payload->>'slug'
                 WHERE slug IS NULL
                   AND payload->>'slug' IS NOT NULL
                   AND payload->>'slug' <> ''"
            );

            DB::statement(
                "UPDATE sp_products
                 SET permalink = payload->>'permalink'
                 WHERE permalink IS NULL
                   AND payload->>'permalink' IS NOT NULL
                   AND payload->>'permalink' <> ''"
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sp_products', 'permalink')) {
            Schema::table('sp_products', function (Blueprint $table) {
                $table->dropColumn('permalink');
            });
        }

        if (Schema::hasColumn('sp_products', 'slug')) {
            Schema::table('sp_products', function (Blueprint $table) {
                $table->dropIndex('sp_products_org_slug_idx');
                $table->dropColumn('slug');
            });
        }
    }
};
