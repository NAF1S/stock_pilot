<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PipelineFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // ml_daily_features is created by DemandIQ's raw SQL migration, so the
        // Laravel test schema does not include it.
        if (! Schema::hasTable('ml_daily_features')) {
            Schema::create('ml_daily_features', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('store_id');
                $table->string('entity_type', 32);
                $table->unsignedBigInteger('entity_id');
                $table->date('feature_date');
                $table->decimal('units_consumed', 14, 4)->default(0);
            });
        }

        $this->org = Organization::create(['name' => 'Freshness Org']);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Manager',
            'email' => 'freshness@example.com',
            'password' => 'secret1234',
            'role' => 'manager',
            'is_active' => true,
        ]);
    }

    private function feature(int $entityId, string $date): void
    {
        DB::table('ml_daily_features')->insert([
            'organization_id' => $this->org->id,
            'store_id' => 1,
            'entity_type' => 'item',
            'entity_id' => $entityId,
            'feature_date' => $date,
            'units_consumed' => 1,
        ]);
    }

    private function forecast(int $entityId, string $date, string $version = 'prophet-v2'): void
    {
        DB::table('sp_demand_forecasts')->insert([
            'organization_id' => $this->org->id,
            'entity_type' => 'item',
            'entity_id' => $entityId,
            'forecast_date' => $date,
            'predicted_quantity' => 1,
            'model_version' => $version,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_fresh_pipeline_reports_ok(): void
    {
        $this->feature(1, now()->toDateString());
        $this->forecast(1, now()->addDay()->toDateString());
        DB::table('sp_stockout_risks')->insert([
            'organization_id' => $this->org->id,
            'entity_type' => 'item',
            'entity_id' => 1,
            'current_stock' => 5,
            'daily_burn_rate' => 1,
            'risk_level' => 'healthy',
            'calculated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/forecasts/pipeline')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('problems', [])
            ->assertJsonPath('feature_lag_days', 0)
            ->assertJsonPath('past_forecast_rows', 0);
    }

    public function test_stale_features_are_reported(): void
    {
        $this->feature(1, now()->subDays(9)->toDateString());
        $this->forecast(1, now()->addDay()->toDateString());

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/forecasts/pipeline')->assertOk();

        $response->assertJsonPath('status', 'stale');
        $this->assertContains('features_stale_9d', $response->json('problems'));
    }

    public function test_past_dated_forecasts_are_reported(): void
    {
        $this->feature(1, now()->toDateString());
        $this->forecast(1, now()->subDay()->toDateString());

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/forecasts/pipeline')->assertOk();

        $response->assertJsonPath('past_forecast_rows', 1);
        $this->assertContains('past_forecast_rows_1', $response->json('problems'));
    }

    public function test_legacy_baseline_rows_do_not_count_as_forecasts(): void
    {
        $this->feature(1, now()->toDateString());
        $this->forecast(1, now()->subDay()->toDateString(), 'baseline-v1');

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/forecasts/pipeline')->assertOk();

        $response->assertJsonPath('forecast_rows', 0);
        $this->assertContains('no_forecasts', $response->json('problems'));
    }

    public function test_pipeline_is_tenant_scoped(): void
    {
        $other = Organization::create(['name' => 'Other']);
        $otherUser = User::create([
            'organization_id' => $other->id,
            'name' => 'Other',
            'email' => 'other-freshness@example.com',
            'password' => 'secret1234',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->feature(1, now()->toDateString());
        $this->forecast(1, now()->addDay()->toDateString());

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/forecasts/pipeline')
            ->assertOk()
            ->assertJsonPath('feature_rows', 0)
            ->assertJsonPath('forecast_rows', 0);
    }
}
