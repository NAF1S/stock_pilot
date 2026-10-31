<?php

namespace App\Jobs;

use App\Jobs\Processors\TrafficProcessor;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use App\Services\ProductSyncProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $webhookEventId)
    {
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $event = WebhookEvent::lockForUpdate()->find($this->webhookEventId);

            if (! $event || in_array($event->status, ['processed', 'duplicate', 'noop'], true)) {
                return;
            }

            $event->status = 'processing';
            $event->save();

            $store = $event->store()->lockForUpdate()->first();

            if (! $store) {
                $event->status = 'failed';
                $event->processing_error = 'Store connection not found';
                $event->save();

                return;
            }

            $body = json_decode((string) $event->payload, true) ?? [];

            if ($event->event_type === 'order.sync') {
                $orderData = $body['order'] ?? $body;

                // Carry the plugin's event-time envelope into the engine so
                // movements/status rows use WooCommerce time, not delivery time.
                if (isset($body['event']) && is_array($body['event'])) {
                    $orderData['event'] = $body['event'];
                }

                // A minimal cancelled payload is the plugin's delete/trash
                // signal. If the order does not exist in the portal, do not
                // create a phantom order for it.
                if (($orderData['status'] ?? null) === 'cancelled' && empty($orderData['items'])) {
                    $existing = Order::where('organization_id', $event->organization_id)
                        ->where('store_id', $event->store_id)
                        ->where('woocommerce_id', $orderData['id'] ?? null)
                        ->first();

                    if (! $existing) {
                        $event->status = 'noop';
                        $event->processed_at = now();
                        $event->save();

                        return;
                    }

                    // Keep the stored order payload (items, dates, refunds); the
                    // delete signal only supplies the id and status.
                    if (is_array($existing->payload)) {
                        $orderData = array_replace($existing->payload, $orderData);
                    }

                    $orderData['status'] = 'cancelled';
                }

                $result = app(InventoryEngine::class)->processOrderEvent(
                    $orderData,
                    $store,
                    $event,
                );

                $event->status = $result['action'] === 'noop' ? 'noop' : 'processed';
                $event->processing_error = $result['warning'] ?? null;
            } elseif (in_array($event->event_type, ['product.sync', 'product.delete'], true)) {
                app(ProductSyncProcessor::class)->syncProduct(
                    $body['product'] ?? $body,
                    $store,
                    $event->event_type === 'product.delete',
                );

                $event->status = 'processed';
            } elseif ($event->event_type === 'traffic.rollup') {
                $result = app(TrafficProcessor::class)->process($body['traffic'] ?? $body, $store);

                $event->status = ($result['action'] ?? null) === 'noop' ? 'noop' : 'processed';

                if (($result['action'] ?? null) === 'noop') {
                    $event->processing_error = $result['reason'] ?? 'Traffic rollup was not stored.';
                }
            } else {
                $event->status = 'noop';
            }

            $store->last_sync_at = now();
            $store->sync_count = ($store->sync_count ?? 0) + 1;
            $store->last_error = null;
            $store->save();

            $event->processed_at = now();
            $event->save();
        });
    }
}
