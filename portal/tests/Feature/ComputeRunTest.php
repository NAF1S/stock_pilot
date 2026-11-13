<?php

namespace Tests\Feature;

use App\Jobs\RunForecastJob;
use App\Models\ComputeRun;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComputeRunTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Compute Org']);

        $this->admin = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Admin',
            'email' => 'compute-admin@example.com',
            'password' => 'secret1234',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Staff',
            'email' => 'compute-staff@example.com',
            'password' => 'secret1234',
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_compute_creates_a_run_and_dispatches_the_modal_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/forecasts/compute');

        $response->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $this->assertDatabaseHas('sp_compute_runs', [
            'organization_id' => $this->org->id,
            'status' => 'queued',
            'trigger_source' => 'manual',
            'triggered_by' => $this->admin->id,
        ]);

        Queue::assertPushed(RunForecastJob::class);
    }

    public function test_compute_reuses_an_in_flight_run(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $run = ComputeRun::create([
            'organization_id' => $this->org->id,
            'status' => 'running',
            'trigger_source' => 'manual',
            'triggered_by' => $this->admin->id,
        ]);

        $this->postJson('/api/forecasts/compute')
            ->assertOk()
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('status', 'running');

        Queue::assertNotPushed(RunForecastJob::class);
        $this->assertSame(1, ComputeRun::count());
    }

    public function test_compute_status_returns_progress_and_eta(): void
    {
        Sanctum::actingAs($this->admin);

        $run = ComputeRun::create([
            'organization_id' => $this->org->id,
            'status' => 'running',
            'trigger_source' => 'manual',
            'triggered_by' => $this->admin->id,
            'total_entities' => 10,
            'done_entities' => 5,
            'progress_pct' => 50,
            'started_at' => now()->subSeconds(10),
            'run_id' => 'run-test',
        ]);

        $this->getJson("/api/forecasts/compute/{$run->id}")
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('progress_pct', 50)
            ->assertJsonPath('done_entities', 5)
            ->assertJsonPath('total_entities', 10)
            ->assertJsonPath('run_id', 'run-test')
            ->assertJsonStructure(['eta_seconds']);
    }

    public function test_compute_status_is_tenant_scoped(): void
    {
        $otherOrg = Organization::create(['name' => 'Other Org']);
        $otherUser = User::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other',
            'email' => 'compute-other@example.com',
            'password' => 'secret1234',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $run = ComputeRun::create([
            'organization_id' => $this->org->id,
            'status' => 'running',
            'trigger_source' => 'manual',
        ]);

        Sanctum::actingAs($otherUser);

        $this->getJson("/api/forecasts/compute/{$run->id}")->assertNotFound();
    }

    public function test_staff_cannot_start_a_compute_run(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/forecasts/compute')->assertForbidden();
    }

    public function test_the_job_posts_the_run_to_the_modal_trigger(): void
    {
        Http::fake([
            'modal.test/*' => Http::response(['status' => 'queued'], 202),
        ]);

        config([
            'services.modal.train_url' => 'https://modal.test/train',
            'services.modal.train_token' => 'test-token',
        ]);

        $run = ComputeRun::create([
            'organization_id' => $this->org->id,
            'status' => 'queued',
            'trigger_source' => 'manual',
            'triggered_by' => $this->admin->id,
        ]);

        (new RunForecastJob($run->id))->handle();

        Http::assertSent(function ($request) use ($run) {
            return $request->url() === 'https://modal.test/train'
                && $request['token'] === 'test-token'
                && $request['compute_run_id'] === $run->id
                && $request['organization_id'] === $this->org->id;
        });

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNotNull($run->started_at);
    }
}
