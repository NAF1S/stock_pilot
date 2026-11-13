<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sp_compute_runs')) {
            return;
        }

        Schema::create('sp_compute_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('sp_organizations')->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->string('trigger_source', 16)->default('manual');
            $table->integer('total_entities')->default(0);
            $table->integer('done_entities')->default(0);
            $table->smallInteger('progress_pct')->default(0);
            $table->string('run_id', 64)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('sp_users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_compute_runs');
    }
};
