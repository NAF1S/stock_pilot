<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MovementAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name = 'Tenant Org'): array
    {
        $org = Organization::create([
            'name' => $name,
            'currency' => 'BDT',
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => $name . ' Admin',
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'password' => 'secret1234',
            'role' => 'admin',
            'is_active' => true,
        ]);

        return [$org, $user];
    }

    public function test_movement_analytics_returns_timeline_summary_and_breakdown(): void
    {
        [$org, $user] = $this->createTenant('Analytics Tenant');

        // Create item and product
        $leather = Item::create([
            'organization_id' => $org->id,
            'name' => 'Italian Leather',
            'sku' => 'LTH-01',
            'unit' => 'sqft',
            'quantity_on_hand' => 100,
            'cost_price' => 20,
        ]);

        $wallet = Product::create([
            'organization_id' => $org->id,
            'name' => 'Classic Wallet',
            'sku' => 'WLT-BLK',
            'source' => 'manual',
            'quantity_on_hand' => 20,
            'cost' => 50,
        ]);

        // 1. Manual Inward Stock Adjustment (+25 sqft leather)
        ItemMovement::create([
            'organization_id' => $org->id,
            'item_id' => $leather->id,
            'movement_type' => 'adjustment',
            'qty_delta' => 25,
            'reserved_delta' => 0,
            'on_hand_after' => 125,
            'ref_type' => 'adjustment',
            'created_at' => now(),
        ]);

        // 2. Completed order with 2 wallets consuming 4 sqft leather
        $order = Order::create([
            'organization_id' => $org->id,
            'woocommerce_id' => 8001,
            'woocommerce_number' => '8001',
            'status' => 'completed',
            'reservation_state' => 'confirmed',
            'confirmed_at' => now(),
            'order_created_at' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'woocommerce_item_id' => 9001,
            'product_id' => $wallet->id,
            'product_name' => $wallet->name,
            'quantity' => 2,
        ]);

        OrderItemComponent::create([
            'organization_id' => $org->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $wallet->id,
            'item_id' => $leather->id,
            'quantity_per_unit' => 2,
            'order_quantity' => 2,
            'total_quantity' => 4,
            'confirmed_quantity' => 4,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // Test week analytics
        $response = $this->getJson('/api/movements/analytics?period=week');
        $response->assertOk();

        $data = $response->json();
        $this->assertEquals('week', $data['period']);
        $this->assertEquals(25.0, $data['summary']['total_in']);
        // Total out = 2 products + 4 items = 6
        $this->assertEquals(6.0, $data['summary']['total_out']);
        $this->assertEquals(19.0, $data['summary']['net_change']);
        $this->assertNotEmpty($data['timeline']);
        $this->assertNotEmpty($data['breakdown']);

        // Test entity filter: item only
        $resItem = $this->getJson('/api/movements/analytics?period=week&entity=item');
        $resItem->assertOk();
        $itemBreakdown = $resItem->json('breakdown');
        $this->assertCount(1, $itemBreakdown);
        $this->assertEquals('Italian Leather', $itemBreakdown[0]['name']);
        $this->assertEquals(25.0, $itemBreakdown[0]['in_qty']);
        $this->assertEquals(4.0, $itemBreakdown[0]['out_qty']);
    }

    public function test_order_completion_counts_outflow_even_if_stock_was_zero(): void
    {
        [$org, $user] = $this->createTenant('Zero Stock Tenant');

        // Material with 0 on-hand
        $zipper = Item::create([
            'organization_id' => $org->id,
            'name' => 'Brass Zipper',
            'sku' => 'ZIP-01',
            'unit' => 'pcs',
            'quantity_on_hand' => 0,
            'cost_price' => 5,
        ]);

        $bag = Product::create([
            'organization_id' => $org->id,
            'name' => 'Leather Tote Bag',
            'sku' => 'BAG-01',
            'source' => 'manual',
            'quantity_on_hand' => 0,
            'cost' => 80,
        ]);

        // Order completed even though stock was zero
        $order = Order::create([
            'organization_id' => $org->id,
            'woocommerce_id' => 8002,
            'woocommerce_number' => '8002',
            'status' => 'completed',
            'reservation_state' => 'confirmed',
            'confirmed_at' => now(),
            'order_created_at' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'woocommerce_item_id' => 9002,
            'product_id' => $bag->id,
            'product_name' => $bag->name,
            'quantity' => 3,
        ]);

        OrderItemComponent::create([
            'organization_id' => $org->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $bag->id,
            'item_id' => $zipper->id,
            'quantity_per_unit' => 1,
            'order_quantity' => 3,
            'total_quantity' => 3,
            'confirmed_quantity' => 0, // 0 confirmed in database because stock was 0, but delivered!
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/movements/analytics?period=week');
        $response->assertOk();

        // Must count 3 bags and 3 zippers as outbound flow!
        $this->assertEquals(6.0, $response->json('summary.total_out'));
    }

    public function test_cancellation_releases_reservation_and_does_not_count_as_outflow(): void
    {
        [$org, $user] = $this->createTenant('Cancellation Tenant');

        $thread = Item::create([
            'organization_id' => $org->id,
            'name' => 'Waxed Thread',
            'sku' => 'THR-01',
            'unit' => 'm',
            'quantity_on_hand' => 500,
        ]);

        $order = Order::create([
            'organization_id' => $org->id,
            'woocommerce_id' => 8003,
            'woocommerce_number' => '8003',
            'status' => 'cancelled',
            'reservation_state' => 'released',
            'order_created_at' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'woocommerce_item_id' => 9003,
            'product_name' => 'Card Holder',
            'quantity' => 1,
        ]);

        OrderItemComponent::create([
            'organization_id' => $org->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'item_id' => $thread->id,
            'quantity_per_unit' => 10,
            'order_quantity' => 1,
            'total_quantity' => 10,
            'status' => 'released',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/movements/analytics?period=week');
        $response->assertOk();

        $this->assertEquals(0.0, $response->json('summary.total_out'));
        $this->assertEquals(10.0, $response->json('summary.total_released'));
    }

    public function test_return_and_refund_counts_as_inflow_restock(): void
    {
        [$org, $user] = $this->createTenant('Refund Tenant');

        $buckle = Item::create([
            'organization_id' => $org->id,
            'name' => 'Silver Buckle',
            'sku' => 'BCK-01',
            'unit' => 'pcs',
            'quantity_on_hand' => 30,
        ]);

        $belt = Product::create([
            'organization_id' => $org->id,
            'name' => 'Silver Buckle Belt',
            'sku' => 'BLT-01',
            'source' => 'manual',
            'quantity_on_hand' => 10,
        ]);

        $order = Order::create([
            'organization_id' => $org->id,
            'woocommerce_id' => 8004,
            'woocommerce_number' => '8004',
            'status' => 'refunded',
            'reservation_state' => 'returned',
            'returned_at' => now(),
            'order_created_at' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'woocommerce_item_id' => 9004,
            'product_id' => $belt->id,
            'product_name' => $belt->name,
            'quantity' => 1,
        ]);

        OrderItemComponent::create([
            'organization_id' => $org->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $belt->id,
            'item_id' => $buckle->id,
            'quantity_per_unit' => 1,
            'order_quantity' => 1,
            'total_quantity' => 1,
            'returned_quantity' => 1,
            'status' => 'returned',
            'returned_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/movements/analytics?period=week');
        $response->assertOk();

        // 1 belt product + 1 buckle item returned = 2 units inflow!
        $this->assertEquals(2.0, $response->json('summary.total_in'));
        $this->assertEquals(2.0, $response->json('summary.total_returned'));
    }

    public function test_movement_analytics_tenant_isolation(): void
    {
        [$orgA, $userA] = $this->createTenant('Org A Analytics');
        [$orgB, $userB] = $this->createTenant('Org B Analytics');

        $itemA = Item::create([
            'organization_id' => $orgA->id,
            'name' => 'Org A Item',
            'quantity_on_hand' => 50,
        ]);

        ItemMovement::create([
            'organization_id' => $orgA->id,
            'item_id' => $itemA->id,
            'movement_type' => 'adjustment',
            'qty_delta' => 100,
            'reserved_delta' => 0,
            'on_hand_after' => 150,
            'ref_type' => 'adjustment',
            'created_at' => now(),
        ]);

        // User B queries analytics -> should see 0
        Sanctum::actingAs($userB);
        $responseB = $this->getJson('/api/movements/analytics?period=week');
        $responseB->assertOk();
        $this->assertEquals(0.0, $responseB->json('summary.total_in'));
        $this->assertEmpty($responseB->json('breakdown'));

        // User A queries analytics -> sees 100
        Sanctum::actingAs($userA);
        $responseA = $this->getJson('/api/movements/analytics?period=week');
        $responseA->assertOk();
        $this->assertEquals(100.0, $responseA->json('summary.total_in'));
        $this->assertCount(1, $responseA->json('breakdown'));
    }

    public function test_dashboard_reports_both_low_stock_and_out_of_stock_for_items_and_products(): void
    {
        [$org, $user] = $this->createTenant('Dashboard Stock Tenant');

        // Item 1: Out of stock (0 on-hand, threshold 0)
        Item::create([
            'organization_id' => $org->id,
            'name' => 'Out of stock fabric',
            'quantity_on_hand' => 0,
            'low_stock_threshold' => 0,
        ]);

        // Item 2: Low stock (3 on-hand, threshold 10)
        Item::create([
            'organization_id' => $org->id,
            'name' => 'Low stock lining',
            'quantity_on_hand' => 3,
            'low_stock_threshold' => 10,
        ]);

        // Product 1: Out of stock (0 on-hand)
        Product::create([
            'organization_id' => $org->id,
            'name' => 'Out of stock Backpack',
            'source' => 'manual',
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'low_stock_threshold' => 0,
        ]);

        // Product 2: Low stock (2 available, threshold 5)
        Product::create([
            'organization_id' => $org->id,
            'name' => 'Low stock Duffle',
            'source' => 'manual',
            'quantity_on_hand' => 4,
            'quantity_reserved' => 2,
            'low_stock_threshold' => 5,
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/dashboard');
        $response->assertOk();

        $data = $response->json();

        // Both items returned
        $this->assertCount(2, $data['low_stock']);
        $this->assertEquals(2, $data['stock_alerts_count']['items']);

        // Both products returned
        $this->assertCount(2, $data['low_stock_products']);
        $this->assertEquals(2, $data['stock_alerts_count']['products']);
        $this->assertEquals(4, $data['stock_alerts_count']['total']);

        // Movement summary included in dashboard
        $this->assertArrayHasKey('movements', $data);
        $this->assertArrayHasKey('today', $data['movements']);
        $this->assertArrayHasKey('week', $data['movements']);
        $this->assertArrayHasKey('year', $data['movements']);
        $this->assertArrayHasKey('top_moving', $data['movements']);
    }
}
