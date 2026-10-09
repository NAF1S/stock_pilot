<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\StoreConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantSaaSTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_new_organization_and_admin_account(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice Merchant',
            'email' => 'alice@store-alpha.com',
            'password' => 'secret1234',
            'organization_name' => 'Store Alpha',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.name', 'Alice Merchant');
        $response->assertJsonPath('user.email', 'alice@store-alpha.com');
        $response->assertJsonPath('user.role', 'admin');
        $response->assertJsonPath('user.organization.name', 'Store Alpha');
        $response->assertJsonPath('user.organization.currency', 'USD');

        $this->assertAuthenticated();

        $this->assertDatabaseHas('sp_organizations', [
            'name' => 'Store Alpha',
            'currency' => 'USD',
        ]);

        $this->assertDatabaseHas('sp_users', [
            'email' => 'alice@store-alpha.com',
            'role' => 'admin',
        ]);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Alice Merchant',
            'email' => 'alice@store-alpha.com',
            'password' => 'secret1234',
            'organization_name' => 'Store Alpha',
        ])->assertStatus(201);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Imposter Alice',
            'email' => 'alice@store-alpha.com',
            'password' => 'anotherpassword',
            'organization_name' => 'Another Store',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_validates_minimum_password_length(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice Merchant',
            'email' => 'alice@store-alpha.com',
            'password' => 'short',
            'organization_name' => 'Store Alpha',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_tenants_cannot_view_or_access_each_others_items(): void
    {
        // 1. Register Tenant A
        $this->postJson('/api/auth/register', [
            'name' => 'Person A',
            'email' => 'person_a@alpha.com',
            'password' => 'password123',
            'organization_name' => 'Alpha Shop',
        ])->assertStatus(201);

        $userA = User::where('email', 'person_a@alpha.com')->firstOrFail();

        // Tenant A creates an item
        $createA = $this->actingAs($userA)->postJson('/api/items', [
            'name' => 'Alpha Leather Sole',
            'sku' => 'SKU-ALPHA-1',
            'unit' => 'pcs',
            'cost_price' => 15.50,
            'quantity_on_hand' => 100,
        ]);
        $createA->assertStatus(201);
        $itemAId = $createA->json('item.id');

        // 2. Register Tenant B
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/register', [
            'name' => 'Person B',
            'email' => 'person_b@beta.com',
            'password' => 'password123',
            'organization_name' => 'Beta Shop',
        ])->assertStatus(201);

        $userB = User::withoutGlobalScope('organization')->where('email', 'person_b@beta.com')->firstOrFail();

        // Tenant B creates their item
        $createB = $this->actingAs($userB)->postJson('/api/items', [
            'name' => 'Beta Microchip',
            'sku' => 'SKU-BETA-1',
            'unit' => 'pcs',
            'cost_price' => 45.00,
            'quantity_on_hand' => 50,
        ]);
        $createB->assertStatus(201);
        $itemBId = $createB->json('item.id');

        // 3. Tenant B lists items: should ONLY see Beta Microchip
        $listB = $this->actingAs($userB)->getJson('/api/items');
        $listB->assertStatus(200);
        $listB->assertJsonCount(1, 'data');
        $listB->assertJsonFragment(['name' => 'Beta Microchip']);
        $listB->assertJsonMissing(['name' => 'Alpha Leather Sole']);

        // 4. Tenant B tries to directly show, update, adjust, or delete Tenant A's item -> 404
        $this->actingAs($userB)->getJson("/api/items/{$itemAId}")->assertStatus(404);
        $this->actingAs($userB)->putJson("/api/items/{$itemAId}", ['name' => 'Hacked Item'])->assertStatus(404);
        $this->actingAs($userB)->postJson("/api/items/{$itemAId}/adjust", [
            'type' => 'add',
            'quantity' => 10,
        ])->assertStatus(404);
        $this->actingAs($userB)->deleteJson("/api/items/{$itemAId}")->assertStatus(404);

        // 5. Tenant A lists items: should ONLY see Alpha Leather Sole
        $listA = $this->actingAs($userA)->getJson('/api/items');
        $listA->assertStatus(200);
        $listA->assertJsonCount(1, 'data');
        $listA->assertJsonFragment(['name' => 'Alpha Leather Sole']);
        $listA->assertJsonMissing(['name' => 'Beta Microchip']);
    }

    public function test_tenants_cannot_view_or_access_each_others_orders_and_stores(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'currency' => 'USD']);
        $userA = User::create([
            'organization_id' => $orgA->id,
            'name' => 'User A',
            'email' => 'user_a@test.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $orgB = Organization::create(['name' => 'Org B', 'currency' => 'USD']);
        $userB = User::create([
            'organization_id' => $orgB->id,
            'name' => 'User B',
            'email' => 'user_b@test.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        // Create store and order for Org A
        $storeA = StoreConnection::create([
            'organization_id' => $orgA->id,
            'name' => 'Shop A WooCommerce',
            'store_url' => 'https://shop-a.com',
            'api_key' => 'sp_store_key_a',
            'api_secret' => 'store_secret_a',
        ]);

        $orderA = Order::create([
            'organization_id' => $orgA->id,
            'store_id' => $storeA->id,
            'woocommerce_id' => 1001,
            'woocommerce_number' => '1001',
            'status' => 'processing',
            'total' => 120.00,
            'sync_status' => 'processed',
        ]);

        // User B cannot see Store A in store list
        $storeListB = $this->actingAs($userB)->getJson('/api/stores');
        $storeListB->assertStatus(200);
        $storeListB->assertJsonMissing(['name' => 'Shop A WooCommerce']);

        // User B cannot show or rotate key for Store A
        $this->actingAs($userB)->putJson("/api/stores/{$storeA->id}", ['name' => 'Renamed'])->assertStatus(404);
        $this->actingAs($userB)->postJson("/api/stores/{$storeA->id}/rotate-key")->assertStatus(404);

        // User B cannot see Order A in order list
        $orderListB = $this->actingAs($userB)->getJson('/api/orders');
        $orderListB->assertStatus(200);
        $orderListB->assertJsonMissing(['woocommerce_number' => '1001']);

        // User B cannot show, reserve, confirm, or delete Order A
        $this->actingAs($userB)->getJson("/api/orders/{$orderA->id}")->assertStatus(404);
        $this->actingAs($userB)->postJson("/api/orders/{$orderA->id}/reserve")->assertStatus(404);
        $this->actingAs($userB)->postJson("/api/orders/{$orderA->id}/confirm")->assertStatus(404);
        $this->actingAs($userB)->deleteJson("/api/orders/{$orderA->id}")->assertStatus(404);
    }

    public function test_tenant_admin_can_manage_team_members_within_own_organization(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Admin Alice',
            'email' => 'alice@company.com',
            'password' => 'password123',
            'organization_name' => 'Company Alpha',
        ])->assertStatus(201);

        $admin = User::where('email', 'alice@company.com')->firstOrFail();

        // 1. Admin creates a staff member
        $createStaff = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Staff Bob',
            'email' => 'bob@company.com',
            'password' => 'password123',
            'role' => 'staff',
        ]);
        $createStaff->assertStatus(201);
        $createStaff->assertJsonPath('user.role', 'staff');
        $staffId = $createStaff->json('user.id');

        // Staff member belongs to Company Alpha
        $staffUser = User::where('email', 'bob@company.com')->firstOrFail();
        $this->assertEquals($admin->organization_id, $staffUser->organization_id);

        // 2. Staff member logs in and can view inventory
        $this->actingAs($staffUser)->getJson('/api/items')->assertStatus(200);

        // 3. Staff member cannot manage users (role:admin middleware protection)
        $this->actingAs($staffUser)->getJson('/api/users')->assertStatus(403);
        $this->actingAs($staffUser)->postJson('/api/users', [
            'name' => 'Hacker',
            'email' => 'hacker@company.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertStatus(403);

        // 4. Admin cannot delete self
        $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")->assertStatus(422);

        // 5. Admin can deactivate and delete staff member
        $this->actingAs($admin)->patchJson("/api/users/{$staffId}/deactivate")->assertStatus(200);
        $this->assertFalse($staffUser->fresh()->is_active);

        $this->actingAs($admin)->deleteJson("/api/users/{$staffId}")->assertStatus(200);
        $this->assertDatabaseMissing('sp_users', ['id' => $staffId]);
    }

    public function test_webhook_from_store_a_only_creates_orders_in_tenant_a(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'currency' => 'USD']);
        $userA = User::create([
            'organization_id' => $orgA->id,
            'name' => 'Owner A',
            'email' => 'owner_a@test.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
        ]);

        $orgB = Organization::create(['name' => 'Org B', 'currency' => 'USD']);
        $userB = User::create([
            'organization_id' => $orgB->id,
            'name' => 'Owner B',
            'email' => 'owner_b@test.com',
            'password' => 'password123',
            'role' => User::ROLE_ADMIN,
        ]);

        $storeA = StoreConnection::create([
            'organization_id' => $orgA->id,
            'name' => 'Store A',
            'store_url' => 'https://store-a.com',
            'api_key' => 'sp_key_alpha',
            'api_secret' => 'secret_alpha_123456789012345678901234567890',
        ]);

        $timestamp = (string) time();
        $nonce = 'nonce_'.\Illuminate\Support\Str::random(16);
        $eventId = 'evt_'.\Illuminate\Support\Str::random(16);
        $payload = [
            'order' => [
                'id' => 9999,
                'number' => '9999',
                'status' => 'pending',
                'line_items' => [],
            ],
        ];
        $body = json_encode($payload);
        $sig = base64_encode(hash_hmac('sha256', "{$timestamp}:{$nonce}:{$eventId}:{$body}", 'secret_alpha_123456789012345678901234567890', true));

        // Post webhook to portal
        $response = $this->call(
            'POST',
            '/api/webhooks/orders',
            [],
            [],
            [],
            [
                'HTTP_X_API_KEY' => 'sp_key_alpha',
                'HTTP_X_TIMESTAMP' => $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_EVENT_ID' => $eventId,
                'HTTP_X_SIGNATURE' => $sig,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertStatus(200);

        // Check that Order 9999 exists in Org A
        $this->actingAs($userA);
        $this->assertEquals(1, Order::where('woocommerce_id', 9999)->count());

        // Check that Order 9999 is INVISIBLE to Org B
        $this->actingAs($userB);
        $this->assertEquals(0, Order::where('woocommerce_id', 9999)->count());
    }
}
