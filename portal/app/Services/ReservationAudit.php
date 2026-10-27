<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only reconciliation of reserved quantities for every item/product.
 *
 * Three independent sources are compared:
 *   cache  = sp_items/sp_products.quantity_reserved
 *   rows   = SUM(sp_order_item_components.reserved_quantity) where > 0
 *   ledger = SUM(sp_item_movements.reserved_delta)
 *
 * This service never writes. A mismatch is reported with the exact deltas so
 * a repair can be scoped deliberately.
 */
class ReservationAudit
{
    private const EPSILON = 0.00005;

    /** @return array<string, mixed> */
    public function run(?int $orgId = null): array
    {
        $items = $this->auditItems($orgId);
        $products = $this->auditProducts($orgId);

        return [
            'snapshot_at' => now()->toIso8601String(),
            'items' => $items,
            'products' => $products,
            'orphans' => [
                'items' => $this->itemOrphans($orgId),
                'products' => $this->productOrphans($orgId),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(string $kind, int $id, ?int $orgId = null): array
    {
        if ($kind === 'item') {
            $orders = DB::table('sp_order_item_components as c')
                ->join('sp_orders as o', 'o.id', '=', 'c.order_id')
                ->where('c.item_id', $id)
                ->where('c.reserved_quantity', '>', 0)
                ->when($orgId !== null, fn ($q) => $q->where('c.organization_id', $orgId))
                ->select(
                    'c.id',
                    'c.order_id',
                    'o.woocommerce_number',
                    'o.status',
                    'o.reservation_state',
                    'c.reserved_quantity',
                    'c.total_quantity',
                    'c.status as component_status',
                    'c.reserved_at'
                )
                ->orderByDesc('c.reserved_quantity')
                ->get();

            $ledger = DB::table('sp_item_movements')
                ->where('item_id', $id)
                ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
                ->selectRaw('movement_type, COUNT(*) AS entries, COALESCE(SUM(reserved_delta), 0) AS reserved_delta, COALESCE(SUM(qty_delta), 0) AS qty_delta')
                ->groupBy('movement_type')
                ->orderBy('movement_type')
                ->get();

            return ['kind' => 'item', 'id' => $id, 'orders' => $orders, 'ledger' => $ledger];
        }

        $orders = DB::table('sp_order_item_components as c')
            ->join('sp_orders as o', 'o.id', '=', 'c.order_id')
            ->where(function ($q) use ($id): void {
                $q->where('c.component_product_id', $id)
                    ->orWhere(function ($legacy) use ($id): void {
                        $legacy->where('c.product_id', $id)
                            ->whereNull('c.item_id')
                            ->whereNull('c.component_product_id');
                    });
            })
            ->where('c.reserved_quantity', '>', 0)
            ->when($orgId !== null, fn ($q) => $q->where('c.organization_id', $orgId))
            ->select(
                'c.id',
                'c.order_id',
                'o.woocommerce_number',
                'o.status',
                'o.reservation_state',
                'c.product_id as sellable_product_id',
                'c.reserved_quantity',
                'c.total_quantity',
                'c.status as component_status',
                'c.reserved_at'
            )
            ->orderByDesc('c.reserved_quantity')
            ->get();

        $ledger = DB::table('sp_item_movements')
            ->where('product_id', $id)
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('movement_type, COUNT(*) AS entries, COALESCE(SUM(reserved_delta), 0) AS reserved_delta, COALESCE(SUM(qty_delta), 0) AS qty_delta')
            ->groupBy('movement_type')
            ->orderBy('movement_type')
            ->get();

        return ['kind' => 'product', 'id' => $id, 'orders' => $orders, 'ledger' => $ledger];
    }

    /** @return array<string, mixed> */
    private function auditItems(?int $orgId): array
    {
        $entities = DB::table('sp_items')
            ->whereNull('deleted_at')
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->select('id', 'organization_id', 'name', 'sku', 'quantity_reserved');

        $rows = DB::table('sp_order_item_components')
            ->whereNotNull('item_id')
            ->where('reserved_quantity', '>', 0)
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('organization_id, item_id AS entity_id, SUM(reserved_quantity) AS active_rows, COUNT(*) AS active_row_count, COUNT(DISTINCT order_id) AS active_order_count')
            ->groupBy('organization_id', 'item_id');

        $ledger = DB::table('sp_item_movements')
            ->whereNotNull('item_id')
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('organization_id, item_id AS entity_id, SUM(reserved_delta) AS ledger_reserved')
            ->groupBy('organization_id', 'item_id');

        return $this->combine($entities, $rows, $ledger);
    }

    /** @return array<string, mixed> */
    private function auditProducts(?int $orgId): array
    {
        $entities = DB::table('sp_products')
            ->whereNull('deleted_at')
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->select('id', 'organization_id', 'name', 'sku', 'quantity_reserved');

        // A product row is either a sub-product component or a legacy self row
        // (no item, no component_product_id) resolved through product_id.
        $rows = DB::table('sp_order_item_components')
            ->where('reserved_quantity', '>', 0)
            ->where(function ($q): void {
                $q->whereNotNull('component_product_id')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('item_id')->whereNull('component_product_id');
                    });
            })
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('organization_id, COALESCE(component_product_id, product_id) AS entity_id, SUM(reserved_quantity) AS active_rows, COUNT(*) AS active_row_count, COUNT(DISTINCT order_id) AS active_order_count')
            ->groupBy('organization_id', DB::raw('COALESCE(component_product_id, product_id)'));

        $ledger = DB::table('sp_item_movements')
            ->whereNotNull('product_id')
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->selectRaw('organization_id, product_id AS entity_id, SUM(reserved_delta) AS ledger_reserved')
            ->groupBy('organization_id', 'product_id');

        return $this->combine($entities, $rows, $ledger);
    }

    /** @return array<string, mixed> */
    private function combine($entities, $rows, $ledger): array
    {
        $records = DB::query()
            ->fromSub($entities, 'e')
            ->leftJoinSub($rows, 'r', function ($join): void {
                $join->on('r.entity_id', '=', 'e.id')
                    ->on('r.organization_id', '=', 'e.organization_id');
            })
            ->leftJoinSub($ledger, 'l', function ($join): void {
                $join->on('l.entity_id', '=', 'e.id')
                    ->on('l.organization_id', '=', 'e.organization_id');
            })
            ->selectRaw('e.id, e.organization_id, e.name, e.sku, e.quantity_reserved AS cache, COALESCE(r.active_rows, 0) AS active_rows, COALESCE(r.active_row_count, 0) AS active_row_count, COALESCE(r.active_order_count, 0) AS active_order_count, COALESCE(l.ledger_reserved, 0) AS ledger_reserved')
            ->orderBy('e.name')
            ->get();

        $result = [];
        $mismatched = 0;

        foreach ($records as $record) {
            $cache = round((float) $record->cache, 4);
            $activeRows = round((float) $record->active_rows, 4);
            $ledgerReserved = round((float) $record->ledger_reserved, 4);

            $flags = [];
            if ($this->differs($cache, $activeRows)) {
                $flags[] = 'cache≠rows';
            }
            if ($this->differs($cache, $ledgerReserved)) {
                $flags[] = 'cache≠ledger';
            }
            if ($this->differs($activeRows, $ledgerReserved)) {
                $flags[] = 'rows≠ledger';
            }

            if ($flags !== []) {
                $mismatched++;
            }

            $result[] = [
                'id' => (int) $record->id,
                'organization_id' => (int) $record->organization_id,
                'name' => $record->name,
                'sku' => $record->sku,
                'cache' => $cache,
                'active_rows' => $activeRows,
                'ledger' => $ledgerReserved,
                'cache_minus_rows' => round($cache - $activeRows, 4),
                'cache_minus_ledger' => round($cache - $ledgerReserved, 4),
                'rows_minus_ledger' => round($activeRows - $ledgerReserved, 4),
                'active_row_count' => (int) $record->active_row_count,
                'active_order_count' => (int) $record->active_order_count,
                'flags' => $flags,
            ];
        }

        usort($result, function (array $a, array $b): int {
            $severityA = abs($a['cache_minus_rows']) + abs($a['cache_minus_ledger']) + abs($a['rows_minus_ledger']);
            $severityB = abs($b['cache_minus_rows']) + abs($b['cache_minus_ledger']) + abs($b['rows_minus_ledger']);

            return $severityB <=> $severityA;
        });

        return [
            'scanned' => count($result),
            'mismatched' => $mismatched,
            'rows' => $result,
        ];
    }

    /** @return array<string, mixed> */
    private function itemOrphans(?int $orgId): array
    {
        $row = DB::table('sp_order_item_components as c')
            ->leftJoin('sp_items as i', 'i.id', '=', 'c.item_id')
            ->whereNotNull('c.item_id')
            ->where('c.reserved_quantity', '>', 0)
            ->when($orgId !== null, fn ($q) => $q->where('c.organization_id', $orgId))
            ->where(fn ($q) => $q->whereNull('i.id')->orWhereNotNull('i.deleted_at'))
            ->selectRaw('COUNT(*) AS entries, COALESCE(SUM(c.reserved_quantity), 0) AS reserved')
            ->first();

        return [
            'entries' => (int) ($row->entries ?? 0),
            'reserved' => round((float) ($row->reserved ?? 0), 4),
        ];
    }

    /** @return array<string, mixed> */
    private function productOrphans(?int $orgId): array
    {
        $row = DB::table('sp_order_item_components as c')
            ->leftJoin('sp_products as p', DB::raw('p.id = COALESCE(c.component_product_id, c.product_id)'))
            ->where('c.reserved_quantity', '>', 0)
            ->where(function ($q): void {
                $q->whereNotNull('c.component_product_id')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('c.item_id')->whereNull('c.component_product_id');
                    });
            })
            ->when($orgId !== null, fn ($q) => $q->where('c.organization_id', $orgId))
            ->where(fn ($q) => $q->whereNull('p.id')->orWhereNotNull('p.deleted_at'))
            ->selectRaw('COUNT(*) AS entries, COALESCE(SUM(c.reserved_quantity), 0) AS reserved')
            ->first();

        return [
            'entries' => (int) ($row->entries ?? 0),
            'reserved' => round((float) ($row->reserved ?? 0), 4),
        ];
    }

    private function differs(float $a, float $b): bool
    {
        return abs($a - $b) > self::EPSILON;
    }
}
