<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the one-off Phase 0B backlog repair.
 *
 * The ledger stays append-only: quantities and `created_at` are never touched.
 * Only the new `occurred_at` metadata column is backfilled, and every change is
 * recorded here so it can be reviewed or reverted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp_timeline_repairs')) {
            return;
        }

        Schema::create('sp_timeline_repairs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('movement_id');
            $table->timestamp('old_created_at')->nullable();
            $table->timestamp('new_occurred_at')->nullable();
            $table->string('source', 64)->nullable();
            $table->string('status', 16)->default('applied');
            $table->text('note')->nullable();
            $table->timestamp('repaired_at')->nullable();
            $table->unsignedBigInteger('repaired_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'movement_id'], 'sp_timeline_repairs_org_movement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_timeline_repairs');
    }
};
