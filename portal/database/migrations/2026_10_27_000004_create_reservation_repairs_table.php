<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for reservation cache/ledger corrections. Each row records the
 * entity, the values before/after, the ledger delta applied, and the movement
 * id (when a correction movement was appended).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp_reservation_repairs')) {
            return;
        }

        Schema::create('sp_reservation_repairs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('entity_type', 16); // item | product
            $table->unsignedBigInteger('entity_id');
            $table->decimal('old_cache', 14, 4)->default(0);
            $table->decimal('new_cache', 14, 4)->default(0);
            $table->decimal('ledger_before', 14, 4)->default(0);
            $table->decimal('ledger_after', 14, 4)->default(0);
            $table->decimal('ledger_delta', 14, 4)->default(0);
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('repaired_by')->nullable();
            $table->timestamp('repaired_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'sp_reservation_repairs_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_reservation_repairs');
    }
};
