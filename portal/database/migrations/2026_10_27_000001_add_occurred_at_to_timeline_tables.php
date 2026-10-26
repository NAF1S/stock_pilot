<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0B — event-time integrity.
 *
 * `created_at` stays the immutable "when the portal recorded it" timestamp.
 * `occurred_at` records when the business event actually happened in
 * WooCommerce, so a queue backlog can no longer shift demand to the wrong day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp_item_movements') && ! Schema::hasColumn('sp_item_movements', 'occurred_at')) {
            Schema::table('sp_item_movements', function (Blueprint $table) {
                $table->timestamp('occurred_at')->nullable();
                $table->index(['organization_id', 'occurred_at'], 'sp_movements_occurred_idx');
            });
        }

        if (Schema::hasTable('sp_webhook_events') && ! Schema::hasColumn('sp_webhook_events', 'occurred_at')) {
            Schema::table('sp_webhook_events', function (Blueprint $table) {
                $table->timestamp('occurred_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sp_item_movements') && Schema::hasColumn('sp_item_movements', 'occurred_at')) {
            Schema::table('sp_item_movements', function (Blueprint $table) {
                $table->dropIndex('sp_movements_occurred_idx');
                $table->dropColumn('occurred_at');
            });
        }

        if (Schema::hasTable('sp_webhook_events') && Schema::hasColumn('sp_webhook_events', 'occurred_at')) {
            Schema::table('sp_webhook_events', function (Blueprint $table) {
                $table->dropColumn('occurred_at');
            });
        }
    }
};
