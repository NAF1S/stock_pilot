<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service to calculate comprehensive stock movements for materials and products
 * across days, weeks, months, and years.
 *
 * Rules:
 * - Stock Out Flow:
 *   - Orders that are confirmed or completed (even if stock was zero at delivery).
 *   - Outward manual stock adjustments.
 * - Stock In Flow:
 *   - User stock additions / restocks (adjustments, initial stock).
 *   - Order returns and restocks (refunded/returned orders).
 * - Active Reservations:
 *   - Orders in processing/pending/reserved state.
 * - Released Stock:
 *   - Orders cancelled or failed (freed from reservation, never deducted).
 */
class MovementAnalyticsService
{
    /**
     * Compute analytics breakdown and timeline for a given period and entity scope.
     *
     * @return array<string, mixed>
     */
    public function getAnalytics(
        int $orgId,
        string $period = 'week',
        ?string $entityScope = 'all',
        ?string $customFrom = null,
        ?string $customTo = null
    ): array {
        [$from, $to, $interval, $periodLabel] = $this->resolveDateRange($period, $customFrom, $customTo);

        // 1. Inward and Outward manual stock adjustments from ledger
        $manualAdjustments = $this->getManualAdjustments($orgId, $from, $to, $entityScope);

        // 2. Order movements (Delivered/Confirmed, Reserved, Returned, Released)
        $orderData = $this->getOrderMovements($orgId, $from, $to, $entityScope);

        // 3. Combine entity breakdown
        $breakdown = $this->mergeEntityBreakdown($orgId, $manualAdjustments['by_entity'], $orderData['by_entity'], $entityScope);

        // 4. Compute timeline buckets
        $timeline = $this->buildTimeline($period, $from, $to, $manualAdjustments['by_bucket'], $orderData['by_bucket']);

        // 5. Aggregate totals
        $totalIn = round($manualAdjustments['total_in'] + $orderData['total_returned'], 4);
        $totalOut = round($manualAdjustments['total_out'] + $orderData['total_confirmed'], 4);
        $netChange = round($totalIn - $totalOut, 4);

        return [
            'period' => $period,
            'period_label' => $periodLabel,
            'range' => [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
            ],
            'summary' => [
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'net_change' => $netChange,
                'total_reserved' => round($orderData['total_reserved'], 4),
                'total_returned' => round($orderData['total_returned'], 4),
                'total_released' => round($orderData['total_released'], 4),
                'total_events' => $manualAdjustments['total_events'] + $orderData['total_events'],
            ],
            'timeline' => $timeline,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Compact summary for dashboard display across Today, This Week, and This Year.
     *
     * @return array<string, mixed>
     */
    public function getDashboardSummary(int $orgId): array
    {
        $today = $this->getAnalytics($orgId, 'day');
        $week = $this->getAnalytics($orgId, 'week');
        $year = $this->getAnalytics($orgId, 'year');

        // Top 5 entities by outward flow in the past week
        $topMoving = collect($week['breakdown'])
            ->sortByDesc('out_qty')
            ->take(5)
            ->values()
            ->all();

        return [
            'today' => $today['summary'],
            'week' => $week['summary'],
            'year' => $year['summary'],
            'top_moving' => $topMoving,
        ];
    }

    /**
     * Resolve date boundaries for the given period.
     *
     * @return array{Carbon, Carbon, string, string}
     */
    private function resolveDateRange(string $period, ?string $customFrom, ?string $customTo): array
    {
        $now = Carbon::now();

        switch ($period) {
            case 'day':
                $from = $now->copy()->startOfDay();
                $to = $now->copy()->endOfDay();
                return [$from, $to, 'hour', "Today (" . $from->format('M d, Y') . ")"];

            case 'week':
                $from = $now->copy()->startOfWeek();
                $to = $now->copy()->endOfWeek();
                return [$from, $to, 'day', "This Week (" . $from->format('M d') . " - " . $to->format('M d, Y') . ")"];

            case 'month':
                $from = $now->copy()->startOfMonth();
                $to = $now->copy()->endOfMonth();
                return [$from, $to, 'day', "This Month (" . $from->format('F Y') . ")"];

            case 'year':
                $from = $now->copy()->startOfYear();
                $to = $now->copy()->endOfYear();
                return [$from, $to, 'month', "This Year (" . $from->format('Y') . ")"];

            case 'custom':
            default:
                $from = $customFrom ? Carbon::parse($customFrom)->startOfDay() : $now->copy()->subDays(30)->startOfDay();
                $to = $customTo ? Carbon::parse($customTo)->endOfDay() : $now->copy()->endOfDay();
                return [$from, $to, 'day', "Custom (" . $from->format('M d, Y') . " - " . $to->format('M d, Y') . ")"];
        }
    }

    /**
     * Retrieve manual adjustments and non-order movements from ItemMovement.
     *
     * @return array<string, mixed>
     */
    private function getManualAdjustments(int $orgId, Carbon $from, Carbon $to, ?string $entityScope): array
    {
        $query = ItemMovement::where('organization_id', $orgId)
            ->where(function ($q) {
                $q->whereNull('ref_type')
                  ->orWhere('ref_type', '!=', 'order');
            })
            ->whereBetween('created_at', [$from, $to]);

        if ($entityScope === 'item') {
            $query->whereNotNull('item_id');
        } elseif ($entityScope === 'product') {
            $query->whereNotNull('product_id');
        }

        $rows = $query->get();

        $totalIn = 0.0;
        $totalOut = 0.0;
        $byEntity = [];
        $byBucket = [];

        foreach ($rows as $row) {
            $qty = (float) $row->qty_delta;
            $isIn = $qty > 0;
            $absQty = abs($qty);

            if ($isIn) {
                $totalIn += $absQty;
            } else {
                $totalOut += $absQty;
            }

            // Entity grouping
            $isItem = $row->item_id !== null;
            $key = $isItem ? "item_{$row->item_id}" : "product_{$row->product_id}";

            if (! isset($byEntity[$key])) {
                $byEntity[$key] = [
                    'entity_type' => $isItem ? 'item' : 'product',
                    'entity_id' => $isItem ? $row->item_id : $row->product_id,
                    'in_qty' => 0.0,
                    'out_qty' => 0.0,
                    'reserved_qty' => 0.0,
                    'returned_qty' => 0.0,
                    'events' => 0,
                ];
            }

            if ($isIn) {
                $byEntity[$key]['in_qty'] += $absQty;
            } else {
                $byEntity[$key]['out_qty'] += $absQty;
            }
            $byEntity[$key]['events']++;

            // Bucket for timeline
            $bucketKey = $row->created_at->format('Y-m-d H:00:00');
            $dateKey = $row->created_at->format('Y-m-d');
            $monthKey = $row->created_at->format('Y-m');

            foreach (['hour' => $bucketKey, 'day' => $dateKey, 'month' => $monthKey] as $bType => $bVal) {
                if (! isset($byBucket[$bType][$bVal])) {
                    $byBucket[$bType][$bVal] = ['in' => 0.0, 'out' => 0.0];
                }
                if ($isIn) {
                    $byBucket[$bType][$bVal]['in'] += $absQty;
                } else {
                    $byBucket[$bType][$bVal]['out'] += $absQty;
                }
            }
        }

        return [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'total_events' => $rows->count(),
            'by_entity' => $byEntity,
            'by_bucket' => $byBucket,
        ];
    }

    /**
     * Compute order-driven movements (Products and Material Components).
     * Strictly accounts for order status (completed/confirmed -> out, cancelled -> released, refunded -> returned).
     *
     * @return array<string, mixed>
     */
    private function getOrderMovements(int $orgId, Carbon $from, Carbon $to, ?string $entityScope): array
    {
        $orders = Order::where('organization_id', $orgId)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('order_created_at', [$from, $to])
                  ->orWhereBetween('confirmed_at', [$from, $to])
                  ->orWhereBetween('updated_at', [$from, $to]);
            })
            ->with(['items', 'items.components'])
            ->get();

        $totalConfirmed = 0.0;
        $totalReserved = 0.0;
        $totalReturned = 0.0;
        $totalReleased = 0.0;
        $totalEvents = 0;

        $byEntity = [];
        $byBucket = [];

        foreach ($orders as $order) {
            $orderStatus = strtolower($order->status);
            $resState = strtolower($order->reservation_state);

            $isConfirmed = $orderStatus === 'completed' || $resState === 'confirmed';
            $isReturned = in_array($orderStatus, ['refunded', 'returned', 'returned-with-charge'], true) || $resState === 'returned';
            $isReleased = in_array($orderStatus, ['cancelled', 'failed', 'trash'], true) || $resState === 'released';
            $isReserved = in_array($orderStatus, ['processing', 'pending', 'on-hold'], true) && ($resState === 'reserved' || $resState === 'partial');

            // Order effective date
            $orderDate = $order->confirmed_at ?? $order->order_created_at ?? $order->created_at;
            $hourKey = $orderDate->format('Y-m-d H:00:00');
            $dateKey = $orderDate->format('Y-m-d');
            $monthKey = $orderDate->format('Y-m');

            // 1. Process Finished Products from Order Items
            if ($entityScope !== 'item') {
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $qty = (float) $item->quantity;
                    $key = "product_{$item->product_id}";

                    if (! isset($byEntity[$key])) {
                        $byEntity[$key] = [
                            'entity_type' => 'product',
                            'entity_id' => $item->product_id,
                            'in_qty' => 0.0,
                            'out_qty' => 0.0,
                            'reserved_qty' => 0.0,
                            'returned_qty' => 0.0,
                            'events' => 0,
                        ];
                    }

                    if ($isConfirmed) {
                        $totalConfirmed += $qty;
                        $byEntity[$key]['out_qty'] += $qty;
                        $this->addToBuckets($byBucket, $hourKey, $dateKey, $monthKey, 0.0, $qty);
                    } elseif ($isReturned) {
                        $totalReturned += $qty;
                        $byEntity[$key]['returned_qty'] += $qty;
                        $this->addToBuckets($byBucket, $hourKey, $dateKey, $monthKey, $qty, 0.0);
                    } elseif ($isReserved) {
                        $totalReserved += $qty;
                        $byEntity[$key]['reserved_qty'] += $qty;
                    } elseif ($isReleased) {
                        $totalReleased += $qty;
                    }

                    $byEntity[$key]['events']++;
                    $totalEvents++;
                }
            }

            // 2. Process Raw Materials from Order Item Components
            if ($entityScope !== 'product') {
                foreach ($order->items as $item) {
                    foreach ($item->components as $comp) {
                        $targetQty = (float) $comp->total_quantity;
                        $compKey = "item_{$comp->item_id}";

                        if (! isset($byEntity[$compKey])) {
                            $byEntity[$compKey] = [
                                'entity_type' => 'item',
                                'entity_id' => $comp->item_id,
                                'in_qty' => 0.0,
                                'out_qty' => 0.0,
                                'reserved_qty' => 0.0,
                                'returned_qty' => 0.0,
                                'events' => 0,
                            ];
                        }

                        if ($isConfirmed) {
                            $qty = (float) $comp->confirmed_quantity > 0 ? (float) $comp->confirmed_quantity : $targetQty;
                            $totalConfirmed += $qty;
                            $byEntity[$compKey]['out_qty'] += $qty;
                            $this->addToBuckets($byBucket, $hourKey, $dateKey, $monthKey, 0.0, $qty);
                        } elseif ($isReturned) {
                            $qty = (float) $comp->returned_quantity > 0 ? (float) $comp->returned_quantity : $targetQty;
                            $totalReturned += $qty;
                            $byEntity[$compKey]['returned_qty'] += $qty;
                            $this->addToBuckets($byBucket, $hourKey, $dateKey, $monthKey, $qty, 0.0);
                        } elseif ($isReserved) {
                            $qty = (float) $comp->reserved_quantity > 0 ? (float) $comp->reserved_quantity : $targetQty;
                            $totalReserved += $qty;
                            $byEntity[$compKey]['reserved_qty'] += $qty;
                        } elseif ($isReleased) {
                            $totalReleased += $targetQty;
                        }

                        $byEntity[$compKey]['events']++;
                        $totalEvents++;
                    }
                }
            }
        }

        return [
            'total_confirmed' => $totalConfirmed,
            'total_reserved' => $totalReserved,
            'total_returned' => $totalReturned,
            'total_released' => $totalReleased,
            'total_events' => $totalEvents,
            'by_entity' => $byEntity,
            'by_bucket' => $byBucket,
        ];
    }

    /**
     * Add inward/outward quantity to hour, day, and month buckets.
     */
    private function addToBuckets(array &$byBucket, string $hourKey, string $dateKey, string $monthKey, float $in, float $out): void
    {
        foreach (['hour' => $hourKey, 'day' => $dateKey, 'month' => $monthKey] as $bType => $bVal) {
            if (! isset($byBucket[$bType][$bVal])) {
                $byBucket[$bType][$bVal] = ['in' => 0.0, 'out' => 0.0];
            }
            $byBucket[$bType][$bVal]['in'] += $in;
            $byBucket[$bType][$bVal]['out'] += $out;
        }
    }

    /**
     * Merge manual adjustment breakdown with order-driven breakdown, loading model metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mergeEntityBreakdown(int $orgId, array $manualByEntity, array $orderByEntity, ?string $entityScope): array
    {
        $allKeys = array_unique(array_merge(array_keys($manualByEntity), array_keys($orderByEntity)));
        $merged = [];

        $itemIds = [];
        $productIds = [];

        foreach ($allKeys as $key) {
            $m = $manualByEntity[$key] ?? null;
            $o = $orderByEntity[$key] ?? null;

            $type = $m['entity_type'] ?? $o['entity_type'];
            $id = $m['entity_id'] ?? $o['entity_id'];

            if ($type === 'item') {
                $itemIds[] = $id;
            } else {
                $productIds[] = $id;
            }

            $inQty = ($m['in_qty'] ?? 0.0) + ($o['returned_qty'] ?? 0.0);
            $outQty = ($m['out_qty'] ?? 0.0) + ($o['out_qty'] ?? 0.0);

            $merged[$key] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'in_qty' => round($inQty, 4),
                'out_qty' => round($outQty, 4),
                'net_qty' => round($inQty - $outQty, 4),
                'reserved_qty' => round($o['reserved_qty'] ?? 0.0, 4),
                'events' => ($m['events'] ?? 0) + ($o['events'] ?? 0),
            ];
        }

        // Bulk load Item and Product names and stock levels
        $items = Item::where('organization_id', $orgId)
            ->whereIn('id', array_unique($itemIds))
            ->get(['id', 'name', 'sku', 'unit', 'quantity_on_hand', 'quantity_reserved'])
            ->keyBy('id');

        $products = Product::where('organization_id', $orgId)
            ->whereIn('id', array_unique($productIds))
            ->get(['id', 'name', 'sku', 'quantity_on_hand', 'quantity_reserved'])
            ->keyBy('id');

        $result = [];
        foreach ($merged as $row) {
            $isItem = $row['entity_type'] === 'item';
            $model = $isItem ? $items->get($row['entity_id']) : $products->get($row['entity_id']);

            if (! $model) {
                continue;
            }

            $result[] = [
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'name' => $model->name,
                'sku' => $model->sku,
                'unit' => $isItem ? $model->unit : null,
                'in_qty' => $row['in_qty'],
                'out_qty' => $row['out_qty'],
                'net_qty' => $row['net_qty'],
                'reserved_qty' => $row['reserved_qty'],
                'current_on_hand' => (float) $model->quantity_on_hand,
                'current_reserved' => (float) $model->quantity_reserved,
                'events' => $row['events'],
            ];
        }

        // Sort by highest outbound flow (or highest total movement)
        usort($result, fn ($a, $b) => ($b['out_qty'] + $b['in_qty']) <=> ($a['out_qty'] + $a['in_qty']));

        return $result;
    }

    /**
     * Build continuous timeline buckets for chart / visual rendering.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeline(string $period, Carbon $from, Carbon $to, array $manualBuckets, array $orderBuckets): array
    {
        $timeline = [];

        if ($period === 'day') {
            // 24 hourly buckets
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $hKey = $cursor->format('Y-m-d H:00:00');
                $in = ($manualBuckets['hour'][$hKey]['in'] ?? 0.0) + ($orderBuckets['hour'][$hKey]['in'] ?? 0.0);
                $out = ($manualBuckets['hour'][$hKey]['out'] ?? 0.0) + ($orderBuckets['hour'][$hKey]['out'] ?? 0.0);

                $timeline[] = [
                    'label' => $cursor->format('H:00'),
                    'full_label' => $cursor->format('ga'),
                    'date' => $cursor->format('Y-m-d H:i'),
                    'in_qty' => round($in, 4),
                    'out_qty' => round($out, 4),
                    'net_qty' => round($in - $out, 4),
                ];
                $cursor->addHour();
            }
        } elseif ($period === 'year') {
            // 12 monthly buckets
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $mKey = $cursor->format('Y-m');
                $in = ($manualBuckets['month'][$mKey]['in'] ?? 0.0) + ($orderBuckets['month'][$mKey]['in'] ?? 0.0);
                $out = ($manualBuckets['month'][$mKey]['out'] ?? 0.0) + ($orderBuckets['month'][$mKey]['out'] ?? 0.0);

                $timeline[] = [
                    'label' => $cursor->format('M'),
                    'full_label' => $cursor->format('F Y'),
                    'date' => $mKey,
                    'in_qty' => round($in, 4),
                    'out_qty' => round($out, 4),
                    'net_qty' => round($in - $out, 4),
                ];
                $cursor->addMonth();
            }
        } else {
            // Daily buckets (Week, Month, Custom)
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $dKey = $cursor->format('Y-m-d');
                $in = ($manualBuckets['day'][$dKey]['in'] ?? 0.0) + ($orderBuckets['day'][$dKey]['in'] ?? 0.0);
                $out = ($manualBuckets['day'][$dKey]['out'] ?? 0.0) + ($orderBuckets['day'][$dKey]['out'] ?? 0.0);

                $timeline[] = [
                    'label' => $cursor->format('D, M j'),
                    'full_label' => $cursor->format('l, M j, Y'),
                    'date' => $dKey,
                    'in_qty' => round($in, 4),
                    'out_qty' => round($out, 4),
                    'net_qty' => round($in - $out, 4),
                ];
                $cursor->addDay();
            }
        }

        return $timeline;
    }
}
