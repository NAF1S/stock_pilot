<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\InventoryEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Release reservations held by orders that no longer exist in WooCommerce
 * (the portal never received a delete/trash event).
 *
 * The release goes through InventoryEngine, so it writes append-only release
 * movements and decrements the item/product caches — it never rewrites the
 * ledger or blindly zeroes a cache. With --delete the order row is removed
 * after the release; its movements are preserved as the stock audit trail.
 *
 * Dry-run by default.
 */
class ReleaseGhostReservations extends Command
{
    protected $signature = 'orders:release-ghosts
        {--org= : Restrict to one organization id}
        {--before= : Release orders created before this date (Y-m-d). Default: 7 days ago}
        {--ids= : Comma-separated portal order ids (overrides --before)}
        {--apply : Write the releases (default is a dry-run report)}
        {--delete : Delete the order rows after releasing; ledger rows are preserved}
        {--limit=200 : Max orders per run}';

    protected $description = 'Release reservations held by stale/deleted WooCommerce orders (dry-run by default)';

    public function handle(InventoryEngine $engine): int
    {
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;
        $before = $this->option('before')
            ? Carbon::parse((string) $this->option('before'))->startOfDay()
            : now()->subDays(7)->startOfDay();

        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        $apply = (bool) $this->option('apply');
        $delete = (bool) $this->option('delete');
        $limit = max(1, (int) $this->option('limit'));

        $query = Order::withoutGlobalScopes()
            ->whereHas('components', fn ($q) => $q->where('reserved_quantity', '>', 0))
            ->with('store')
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->when(
                $ids->isNotEmpty(),
                fn ($q) => $q->whereIn('id', $ids),
                fn ($q) => $q->where('order_created_at', '<', $before)
            )
            ->orderBy('order_created_at')
            ->limit($limit);

        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->info('No orders with active reservations matched.');

            return self::SUCCESS;
        }

        $reservedUnits = DB::table('sp_order_item_components')
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('reserved_quantity', '>', 0)
            ->sum('reserved_quantity');

        $this->info($apply ? 'Releasing ghost reservations — APPLY mode.' : 'Ghost reservation report — DRY RUN.');
        $this->line($ids->isNotEmpty()
            ? 'Filter: order ids '.$ids->implode(', ')
            : 'Filter: created before '.$before->toDateString());
        $this->line("Orders: {$orders->count()} · reserved units: ".$this->fmt((float) $reservedUnits));
        $this->newLine();

        $this->table(
            ['portal id', 'woo #', 'status', 'reservation', 'created', 'store', 'reserved'],
            $orders->map(function (Order $order): array {
                $reserved = DB::table('sp_order_item_components')
                    ->where('order_id', $order->id)
                    ->where('reserved_quantity', '>', 0)
                    ->sum('reserved_quantity');

                return [
                    $order->id,
                    '#'.$order->woocommerce_number,
                    $order->status,
                    $order->reservation_state,
                    (string) ($order->order_created_at ?? '—'),
                    $order->store?->name ?? '—',
                    $this->fmt((float) $reserved),
                ];
            })->all()
        );

        if (! $apply) {
            $this->comment('Dry run — nothing written. Re-run with --apply to release.');
            $this->line('Add --delete to remove the order rows after releasing (ledger rows are kept).');

            return self::SUCCESS;
        }

        $released = 0;
        $deleted = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if (! $order->store) {
                $this->warn("Order #{$order->woocommerce_number}: no store connection, skipped.");
                $failed++;
                continue;
            }

            try {
                DB::transaction(function () use ($order, $engine, $delete): void {
                    $occurredAt = now();

                    $event = WebhookEvent::create([
                        'organization_id' => $order->organization_id,
                        'store_id' => $order->store_id,
                        'woocommerce_order_id' => $order->woocommerce_id,
                        'event_id' => 'ghost-release-'.$order->id.'-'.$occurredAt->getTimestamp().'-'.Str::lower(Str::random(6)),
                        'nonce' => Str::random(32),
                        'event_type' => 'order.sync',
                        'wc_status' => 'cancelled',
                        'occurred_at' => $occurredAt,
                        'payload' => json_encode([
                            'order' => ['id' => $order->woocommerce_id, 'status' => 'cancelled'],
                            'event' => [
                                'occurred_at' => $occurredAt->toIso8601String(),
                                'captured_at' => $occurredAt->toIso8601String(),
                                'reason' => 'ghost_release',
                            ],
                        ]),
                        'status' => 'received',
                    ]);

                    // Preserve the full stored payload; only the status and
                    // event envelope are overridden for the release.
                    $payload = is_array($order->payload) ? $order->payload : [];
                    $payload['id'] = $order->woocommerce_id;
                    $payload['status'] = 'cancelled';
                    $payload['event'] = [
                        'occurred_at' => $occurredAt->toIso8601String(),
                        'captured_at' => $occurredAt->toIso8601String(),
                        'reason' => 'ghost_release',
                    ];

                    $engine->processOrderEvent($payload, $order->store, $event);

                    $event->status = 'processed';
                    $event->processed_at = now();
                    $event->save();

                    if ($delete) {
                        // Components/order items cascade; sp_item_movements has no
                        // FK to the order, so the release ledger rows survive.
                        $order->delete();
                    }
                });

                $released++;

                if ($delete) {
                    $deleted++;
                }

                $this->line("Released #{$order->woocommerce_number}".($delete ? ' and deleted the order row.' : '.'));
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->error("Failed #{$order->woocommerce_number}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Released: {$released}".($delete ? " · deleted: {$deleted}" : '').($failed ? " · failed: {$failed}" : ''));
        $this->line('No ledger or component row was edited; releases are new append-only movements.');
        $this->line('Re-run: php artisan audit:reservations');

        return self::SUCCESS;
    }

    private function fmt(float $value): string
    {
        $rounded = round($value, 4);

        return rtrim(rtrim(number_format($rounded, 4, '.', ''), '0'), '.') ?: '0';
    }
}
