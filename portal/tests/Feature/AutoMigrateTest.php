<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * An install that predates the product-fields migration has the migration file
 * on disk but no record in the `migrations` table. AutoMigrate must detect it
 * as pending and apply it, otherwise the dashboard/product-stock endpoints 500
 * on the missing columns.
 */
test('auto-migrate applies a pending migration to an existing install', function () {
    // Reproduce production: schema from before the product-fields migration,
    // with that migration absent from the ran list.
    $newest = '2026_08_03_000001_add_product_inventory_fields';
    DB::table('migrations')->where('migration', $newest)->delete();

    Schema::table('sp_stock_warnings', function (Blueprint $t) {
        $t->dropConstrainedForeignId('product_id');
    });
    // SQLite's table rebuild drops the column along with the FK; MySQL keeps
    // it, so drop it only if it survived.
    if (Schema::hasColumn('sp_stock_warnings', 'product_id')) {
        Schema::table('sp_stock_warnings', function (Blueprint $t) {
            $t->dropColumn('product_id');
        });
    }
    Schema::table('sp_products', function (Blueprint $t) {
        $t->dropColumn(['cost', 'quantity_on_hand', 'low_stock_threshold']);
    });

    $org = Organization::create(['name' => 'Test Org']);
    $user = User::create([
        'organization_id' => $org->id,
        'name' => 'Admin',
        'email' => 'admin@test.dev',
        'password' => 'password',
        'role' => 'admin',
    ]);

    // The dashboard query references the missing sp_products columns — the
    // AutoMigrate middleware must run the pending migration before the request
    // reaches the controller, turning what would be a 500 into a 200.
    Sanctum::actingAs($user);
    $this->getJson('/api/dashboard')->assertOk();

    expect(Schema::hasColumn('sp_products', 'quantity_on_hand'))->toBeTrue();
    expect(Schema::hasColumn('sp_stock_warnings', 'product_id'))->toBeTrue();
});
