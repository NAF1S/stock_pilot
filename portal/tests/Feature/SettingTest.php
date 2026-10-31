<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Acme Supplies',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'low_stock_email' => 'alerts@acme.com',
        ]);

        $this->admin = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Alice Admin',
            'email' => 'alice@acme.com',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
        ]);

        $this->staff = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Bob Staff',
            'email' => 'bob@acme.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
        ]);
    }

    public function test_can_view_settings(): void
    {
        Sanctum::actingAs($this->admin);

        /** @var SettingService $svc */
        $svc = app(SettingService::class);
        $svc->set($this->org->id, 'allow_negative_stock', false);
        $svc->set($this->org->id, 'warning_cooldown_hours', 24);

        $response = $this->getJson('/api/settings');

        $response->assertOk()
            ->assertJsonPath('organization.name', 'Acme Supplies')
            ->assertJsonPath('organization.currency', 'USD')
            ->assertJsonPath('organization.timezone', 'America/New_York')
            ->assertJsonPath('organization.low_stock_email', 'alerts@acme.com')
            ->assertJsonPath('settings.allow_negative_stock', false)
            ->assertJsonPath('settings.warning_cooldown_hours', 24);
    }

    public function test_can_update_settings_and_subsequent_updates_succeed(): void
    {
        Sanctum::actingAs($this->admin);

        // First update: sets company details and initial settings
        $payload1 = [
            'name' => 'Acme Global',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'low_stock_email' => 'warehouse@acme.de',
            'allow_negative_stock' => true,
            'warning_cooldown_hours' => 12,
            'status_map' => [
                'reserve' => ['processing', 'pending'],
                'confirm' => ['completed'],
                'release' => ['cancelled'],
                'return' => ['refunded'],
            ],
        ];

        $response1 = $this->putJson('/api/settings', $payload1);

        $response1->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('organization.name', 'Acme Global')
            ->assertJsonPath('organization.currency', 'EUR')
            ->assertJsonPath('organization.timezone', 'Europe/Berlin')
            ->assertJsonPath('organization.low_stock_email', 'warehouse@acme.de')
            ->assertJsonPath('settings.allow_negative_stock', true)
            ->assertJsonPath('settings.warning_cooldown_hours', 12)
            ->assertJsonPath('settings.status_map.reserve.0', 'processing');

        $this->assertDatabaseHas('sp_organizations', [
            'id' => $this->org->id,
            'name' => 'Acme Global',
            'currency' => 'EUR',
        ]);

        // Second update: modifies existing settings (testing composite PK update)
        $payload2 = [
            'name' => 'Acme Global Ltd',
            'currency' => 'gbp',
            'allow_negative_stock' => false,
            'warning_cooldown_hours' => 48,
            'status_map' => [
                'reserve' => ['on-hold'],
                'confirm' => ['completed', 'shipped'],
                'release' => ['cancelled'],
                'return' => ['refunded'],
            ],
        ];

        $response2 = $this->putJson('/api/settings', $payload2);

        $response2->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('organization.name', 'Acme Global Ltd')
            ->assertJsonPath('organization.currency', 'GBP')
            ->assertJsonPath('settings.allow_negative_stock', false)
            ->assertJsonPath('settings.warning_cooldown_hours', 48)
            ->assertJsonPath('settings.status_map.reserve.0', 'on-hold');

        // Third update: verify get returns updated values
        $response3 = $this->getJson('/api/settings');
        $response3->assertOk()
            ->assertJsonPath('organization.currency', 'GBP')
            ->assertJsonPath('settings.allow_negative_stock', false)
            ->assertJsonPath('settings.warning_cooldown_hours', 48);
    }

    public function test_status_map_with_unknown_status_is_rejected_and_not_persisted(): void
    {
        Sanctum::actingAs($this->admin);

        // "confimed" is the typo found in the live org: it matches no order, so
        // every order carrying it became a silent no-op.
        $response = $this->putJson('/api/settings', [
            'name' => 'Should Not Persist',
            'status_map' => [
                'reserve' => ['pending', 'confimed'],
                'confirm' => ['completed'],
                'release' => ['cancelled'],
                'return' => ['refunded'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status_map']);

        $message = $response->json('errors.status_map.0');
        $this->assertIsString($message);
        $this->assertStringContainsString('confimed', $message);
        // The message must tell the user what they may use instead.
        $this->assertStringContainsString('on-hold', $message);
        $this->assertStringContainsString('completed', $message);

        // Nothing was written — neither the map nor the sibling org fields.
        $this->assertDatabaseMissing('sp_settings', [
            'organization_id' => $this->org->id,
            'key' => 'status_map',
        ]);
        $this->assertNull(app(SettingService::class)->get($this->org->id, 'status_map'));
        $this->assertDatabaseMissing('sp_organizations', [
            'id' => $this->org->id,
            'name' => 'Should Not Persist',
        ]);
    }

    public function test_status_map_rejects_a_status_listed_in_two_actions(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/settings', [
            'status_map' => [
                'reserve' => ['pending', 'processing'],
                'confirm' => ['processing', 'completed'],
                'release' => ['cancelled'],
                'return' => ['refunded'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status_map']);

        $message = $response->json('errors.status_map.0');
        $this->assertIsString($message);
        $this->assertStringContainsString('processing', $message);
        $this->assertStringContainsString('reserve', $message);
        $this->assertStringContainsString('confirm', $message);

        $this->assertDatabaseMissing('sp_settings', [
            'organization_id' => $this->org->id,
            'key' => 'status_map',
        ]);
    }

    public function test_status_map_rejects_an_unknown_action_key(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/settings', [
            'status_map' => [
                'reserve' => ['pending', 'processing'],
                'confrim' => ['completed'], // typo'd action key
                'release' => ['cancelled'],
                'return' => ['refunded'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status_map']);

        $message = $response->json('errors.status_map.0');
        $this->assertIsString($message);
        $this->assertStringContainsString('confrim', $message);
        $this->assertStringContainsString('reserve, confirm, release, return', $message);

        $this->assertDatabaseMissing('sp_settings', [
            'organization_id' => $this->org->id,
            'key' => 'status_map',
        ]);
    }

    public function test_status_map_rejects_empty_reserve_and_confirm_lists(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/settings', [
            'status_map' => [
                'reserve' => [],
                'confirm' => [],
                'release' => ['cancelled'],
                'return' => ['refunded'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status_map.reserve', 'status_map.confirm']);

        $this->assertDatabaseMissing('sp_settings', [
            'organization_id' => $this->org->id,
            'key' => 'status_map',
        ]);
    }

    public function test_status_map_is_normalized_before_it_is_saved(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/settings', [
            'status_map' => [
                'reserve' => [' PENDING ', 'pending', 'Processing', ''],
                'confirm' => [' Completed', 'completed', '   '],
                'release' => ['Cancelled '],
                'return' => ['Refunded', 'refunded'],
            ],
        ]);

        $expected = [
            'reserve' => ['pending', 'processing'],
            'confirm' => ['completed'],
            'release' => ['cancelled'],
            'return' => ['refunded'],
        ];

        $response->assertOk()
            ->assertJsonPath('settings.status_map', $expected);

        $this->assertSame($expected, app(SettingService::class)->get($this->org->id, 'status_map'));
    }

    public function test_status_map_accepts_statuses_observed_in_orders_and_webhook_events(): void
    {
        Sanctum::actingAs($this->admin);

        // A plugin-specific status this org has actually received is legitimate
        // even though it is not in the built-in list.
        DB::table('sp_orders')->insert([
            'organization_id' => $this->org->id,
            'woocommerce_id' => 9001,
            'status' => 'ready-to-ship',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storeId = DB::table('sp_store_connections')->insertGetId([
            'organization_id' => $this->org->id,
            'name' => 'Main store',
            'store_url' => 'https://shop.example.com',
            'api_key_hash' => hash('sha256', 'sp_test_key'),
            'api_secret_encrypted' => 'irrelevant-for-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sp_webhook_events')->insert([
            'organization_id' => $this->org->id,
            'store_id' => $storeId,
            'event_id' => 'evt-1',
            'nonce' => 'nonce-1',
            'event_type' => 'order.sync',
            'wc_status' => 'packing',
            'payload' => '{}',
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson('/api/settings', [
            'status_map' => [
                'reserve' => ['pending', 'ready-to-ship'],
                'confirm' => ['completed'],
                'release' => ['cancelled', 'packing'],
                'return' => ['refunded'],
            ],
        ])->assertOk()
            ->assertJsonPath('settings.status_map.reserve', ['pending', 'ready-to-ship'])
            ->assertJsonPath('settings.status_map.release', ['cancelled', 'packing']);

        // A status nobody has ever seen is still rejected.
        $response = $this->putJson('/api/settings', [
            'status_map' => [
                'reserve' => ['pending'],
                'confirm' => ['completed'],
                'release' => ['cancelled'],
                'return' => ['refunded', 'not-a-real-status'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status_map']);
        $this->assertStringContainsString('not-a-real-status', $response->json('errors.status_map.0'));
    }

    public function test_staff_cannot_update_settings(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->putJson('/api/settings', [
            'name' => 'Hacked Org',
        ]);

        $response->assertForbidden();
    }

    public function test_setting_service_direct_operations(): void
    {
        /** @var SettingService $svc */
        $svc = app(SettingService::class);

        // Test default
        $this->assertSame('default_val', $svc->get($this->org->id, 'nonexistent', 'default_val'));

        // Test set and get
        $svc->set($this->org->id, 'my_key', ['nested' => 'val']);
        $this->assertSame(['nested' => 'val'], $svc->get($this->org->id, 'my_key'));

        // Test update existing
        $svc->set($this->org->id, 'my_key', 'updated_scalar');
        $this->assertSame('updated_scalar', $svc->get($this->org->id, 'my_key'));

        // Test all
        $all = $svc->all($this->org->id);
        $this->assertArrayHasKey('my_key', $all);
        $this->assertSame('updated_scalar', $all['my_key']);

        // Test forget
        $svc->forget($this->org->id, 'my_key');
        $this->assertNull($svc->get($this->org->id, 'my_key'));
    }

    public function test_test_email_requires_configured_email(): void
    {
        Sanctum::actingAs($this->admin);

        $this->org->update(['low_stock_email' => null]);

        $response = $this->postJson('/api/settings/test-email');

        $response->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_test_email_sends_successfully(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/settings/test-email');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sent_to', 'alerts@acme.com');
    }
}
