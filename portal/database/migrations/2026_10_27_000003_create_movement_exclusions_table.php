<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movements listed here are hidden from analytics only. The ledger row itself
 * is never edited or deleted — this is a read-side filter so a known bad
 * manual-adjustment batch cannot distort charts or burn-rate calculations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp_movement_exclusions')) {
            return;
        }

        Schema::create('sp_movement_exclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('movement_id')->unique();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_movement_exclusions');
    }
};
