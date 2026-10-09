<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockWarning;
use App\Models\StoreConnection;
use App\Services\AssetValuation;
use App\Services\MovementAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private AssetValuation $valuation,
        private MovementAnalyticsService $analytics
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $lowStock = Item::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->where('quantity_on_hand', '<=', 0)
                  ->orWhere(function ($sub) {
                      $sub->where('low_stock_threshold', '>', 0)
                          ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold');
                  });
            })
            ->orderBy('quantity_on_hand')
            ->limit(15)
            ->get();

        $lowStockProducts = Product::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->whereRaw('(quantity_on_hand - COALESCE(quantity_reserved, 0)) <= 0')
                  ->orWhere(function ($sub) {
                      $sub->where('low_stock_threshold', '>', 0)
                          ->whereRaw('(quantity_on_hand - COALESCE(quantity_reserved, 0)) <= low_stock_threshold');
                  });
            })
            ->orderBy('quantity_on_hand')
            ->limit(15)
            ->get();

        $ordersByStatus = Order::where('organization_id', $orgId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get();

        $recentOrders = Order::where('organization_id', $orgId)
            ->orderByDesc('order_created_at')
            ->limit(8)
            ->get(['id', 'woocommerce_number', 'customer_name', 'status', 'reservation_state', 'total', 'order_created_at']);

        $stockAlertItemsCount = Item::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->where('quantity_on_hand', '<=', 0)
                  ->orWhere(function ($sub) {
                      $sub->where('low_stock_threshold', '>', 0)
                          ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold');
                  });
            })->count();

        $stockAlertProductsCount = Product::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->whereRaw('(quantity_on_hand - COALESCE(quantity_reserved, 0)) <= 0')
                  ->orWhere(function ($sub) {
                      $sub->where('low_stock_threshold', '>', 0)
                          ->whereRaw('(quantity_on_hand - COALESCE(quantity_reserved, 0)) <= low_stock_threshold');
                  });
            })->count();

        return response()->json([
            'asset_value' => $this->valuation->total($orgId),
            'asset_value_by_category' => $this->valuation->byCategory($orgId),
            'stock_alerts_count' => [
                'items' => $stockAlertItemsCount,
                'products' => $stockAlertProductsCount,
                'total' => $stockAlertItemsCount + $stockAlertProductsCount,
            ],
            'low_stock' => $lowStock->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'unit' => $item->unit,
                'quantity_on_hand' => (float) $item->quantity_on_hand,
                'low_stock_threshold' => (float) $item->low_stock_threshold,
                'is_out_of_stock' => (float) $item->quantity_on_hand <= 0,
            ]),
            'low_stock_products' => $lowStockProducts->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'quantity_on_hand' => (float) $p->quantity_on_hand,
                'quantity_reserved' => (float) $p->quantity_reserved,
                'available' => $p->available(),
                'low_stock_threshold' => (float) $p->low_stock_threshold,
                'is_out_of_stock' => $p->available() <= 0,
            ]),
            'movements' => $this->analytics->getDashboardSummary($orgId),
            'active_warnings' => StockWarning::where('organization_id', $orgId)
                ->where('status', 'active')
                ->count(),
            'orders_by_status' => $ordersByStatus,
            'recent_orders' => $recentOrders,
            'store_health' => [
                'connected' => StoreConnection::where('organization_id', $orgId)->where('is_active', true)->count(),
                'total' => StoreConnection::where('organization_id', $orgId)->count(),
            ],
        ]);
    }
}
