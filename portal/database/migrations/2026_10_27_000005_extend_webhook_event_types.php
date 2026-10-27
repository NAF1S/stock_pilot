<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Open sp_webhook_events.event_type to the new traffic.rollup value.
 *
 * Laravel's enum() is a VARCHAR + CHECK on Postgres and SQLite, and a real
 * ENUM on MySQL. The portable move is a plain VARCHAR:
 *   - Postgres: drop only the event_type check (the status check stays);
 *   - MySQL: widen the enum column to VARCHAR;
 *   - SQLite: rebuild the table without the CHECK (tests / local dev).
 *
 * The migration is intentionally idempotent and never narrows the column back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sp_webhook_events')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sp_webhook_events DROP CONSTRAINT IF EXISTS sp_webhook_events_event_type_check');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sp_webhook_events MODIFY event_type VARCHAR(32) NOT NULL');
            DB::statement("ALTER TABLE sp_webhook_events MODIFY status VARCHAR(16) NOT NULL DEFAULT 'received'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqlite();
        }
    }

    public function down(): void
    {
        // Never narrow the accepted event types back to a closed list.
    }

    private function rebuildSqlite(): void
    {
        $definition = DB::table('sqlite_master')
            ->where('type', 'table')
            ->where('name', 'sp_webhook_events')
            ->value('sql');

        if (! is_string($definition) || ! str_contains(strtolower($definition), 'check')) {
            return;
        }

        $columns = Schema::getColumnListing('sp_webhook_events');
        $hasOccurredAt = in_array('occurred_at', $columns, true);

        Schema::disableForeignKeyConstraints();

        Schema::rename('sp_webhook_events', 'sp_webhook_events_old');

        Schema::create('sp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations');
            $table->foreignId('store_id')->constrained('sp_store_connections');
            $table->unsignedBigInteger('woocommerce_order_id')->nullable();
            $table->string('event_id', 64);
            $table->string('nonce', 64);
            $table->string('signature', 128)->nullable();
            $table->string('event_type', 32);
            $table->string('wc_status', 32)->nullable();
            $table->mediumText('payload');
            $table->string('status', 16)->default('received');
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            // Distinct names: the renamed old table still owns the original
            // index names until it is dropped.
            $table->unique(['store_id', 'nonce'], 'sp_webhook_events_store_nonce_v2');
            $table->unique(['store_id', 'event_id'], 'sp_webhook_events_store_event_v2');
            $table->index(['store_id', 'status'], 'sp_webhook_events_store_status_v2');
        });

        $occurredSelect = $hasOccurredAt ? 'occurred_at' : 'NULL as occurred_at';

        DB::statement(
            "INSERT INTO sp_webhook_events
                (id, organization_id, store_id, woocommerce_order_id, event_id, nonce, signature,
                 event_type, wc_status, payload, status, processing_error, processed_at, occurred_at,
                 created_at, updated_at)
             SELECT id, organization_id, store_id, woocommerce_order_id, event_id, nonce, signature,
                    event_type, wc_status, payload, status, processing_error, processed_at, {$occurredSelect},
                    created_at, updated_at
             FROM sp_webhook_events_old"
        );

        Schema::drop('sp_webhook_events_old');

        Schema::enableForeignKeyConstraints();
    }
};
