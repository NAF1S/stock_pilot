<?php

namespace App\Services;

use App\Models\Item;
use App\Models\OrderItemComponent;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-side lookup of the orders currently reserving an item or product.
 *
 * A reservation lives on sp_order_item_components (reserved_quantity > 0).
 * Items are matched by item_id. Products are matched by component_product_id,
 * which covers both the product's own finished-goods row and cases where the
 * product is a sub-component of another sellable product. Legacy rows created
 * before component_product_id existed are matched through product_id.
 */
class ReservationQuery
{
    private const LIMIT = 200;

    /** @return Collection<int, OrderItemComponent> */
    public function forItem(int $orgId, Item $item): Collection
    {
        return OrderItemComponent::query()
            ->where('organization_id', $orgId)
            ->where('item_id', $item->id)
            ->where('reserved_quantity', '>', 0)
            ->with($this->relations())
            ->orderByDesc('reserved_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /** @return Collection<int, OrderItemComponent> */
    public function forProduct(int $orgId, Product $product): Collection
    {
        return OrderItemComponent::query()
            ->where('organization_id', $orgId)
            ->where(function ($query) use ($product): void {
                $query->where('component_product_id', $product->id)
                    ->orWhere(function ($legacy) use ($product): void {
                        $legacy->where('product_id', $product->id)
                            ->whereNull('item_id')
                            ->whereNull('component_product_id');
                    });
            })
            ->where('reserved_quantity', '>', 0)
            ->with($this->relations())
            ->orderByDesc('reserved_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /** @return array<string, mixed> */
    public function payload(OrderItemComponent $row, string $kind, int $entityId): array
    {
        $sellable = $row->product;
        $componentProductId = $row->component_product_id !== null ? (int) $row->component_product_id : null;

        $isDirectProduct = $kind === 'product'
            && (int) $row->product_id === $entityId
            && ($componentProductId === null || $componentProductId === $entityId);

        return [
            'component_id' => $row->id,
            'order_id' => $row->order_id,
            'order_number' => $row->order?->woocommerce_number,
            'order_status' => $row->order?->status,
            'reservation_state' => $row->order?->reservation_state,
            'customer_name' => $row->order?->customer_name,
            'store_name' => $row->order?->store?->name,
            'order_created_at' => $row->order?->order_created_at?->toIso8601String(),
            'reserved_at' => $row->reserved_at?->toIso8601String(),
            'component_status' => $row->status,
            'quantity_per_unit' => (float) $row->quantity_per_unit,
            'order_quantity' => (float) $row->order_quantity,
            'total_quantity' => (float) $row->total_quantity,
            'reserved_quantity' => (float) $row->reserved_quantity,
            'order_item_name' => $row->orderItem?->product_name,
            'sellable_product' => $sellable ? [
                'id' => $sellable->id,
                'name' => $sellable->name,
                'sku' => $sellable->sku,
            ] : null,
            'usage' => $kind === 'product' ? ($isDirectProduct ? 'direct' : 'component') : null,
        ];
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'order:id,woocommerce_number,status,reservation_state,customer_name,store_id,order_created_at',
            'order.store:id,name',
            'orderItem:id,product_id,product_name,quantity',
            'product:id,name,sku',
        ];
    }
}
