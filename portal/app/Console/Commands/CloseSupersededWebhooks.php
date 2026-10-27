<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Close historical webhook rows that were processed outside ProcessWebhookEvent
 * but left with status=received.
 *
 * The rule is conservative:
 *   - reprocess-* / delete-* / ghost-release-* are portal-generated events whose
 *     engine call already ran; mark them processed/noop.
 *   - plugin ord-* events are only closed when the order no longer exists or a
 *     later event/update superseded them. The latest event for a live order is
 *     left as `review` and never replayed.
 *
 * Dry-run by default; --apply writes. No payload is replayed.
 */
class CloseSupersededWebhooks extends Command
{
    protected $signature = 'webhooks:close-superseded
        {--apply : Write the terminal statuses (default is a dry-run proposal)}
        {--org= : Restrict to one organization id}
        {--show=20 : How many sample rows to print}
        {--json= : Write the full proposal to a JSON file}';

    protected $description = 'Close historical received webhook rows that were processed outside the queue (dry-run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;
        $show = max(0, (int) $this->option('show'));

        $events = DB::table('sp_webhook_events as e')
            ->leftJoin('sp_orders as o', function ($join): void {
                $join->on('o.organization_id', '=', 'e.organization_id')
                    ->on('o.woocommerce_id', '=', 'e.woocommerce_order_id');
            })
            ->where('e.status', 'received')
            ->when($orgId !== null, fn ($q) => $q->where('e.organization_id', $orgId))
            ->orderBy('e.id')
            ->get([
                'e.id',
                'e.organization_id',
                'e.event_id',
                'e.event_type',
                'e.wc_status',
                'e.woocommerce_order_id',
                'e.created_at',
                'o.id as order_row_id',
                'o.woocommerce_number',
                'o.status as order_status',
                'o.reservation_state',
                'o.updated_at as order_updated_at',
                'o.last_webhook_event_id',
            ]);

        $proposals = $events->map(fn ($row) => $this->propose($row))->values();
        $summary = $proposals
            ->groupBy(fn ($row) => $row['kind'].' → '.$row['proposal'])
            ->map->count()
            ->sortKeys();

        $this->info($apply
            ? 'Webhook close-out — APPLY mode (statuses only; payloads are never replayed).'
            : 'Webhook close-out — DRY RUN. Add --apply to write.');
        $this->line('Received rows scanned: '.$proposals->count());

        if ($proposals->isEmpty()) {
            $this->info('No received rows found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['proposal', 'rows'],
            $summary->map(fn ($count, $label) => [$label, $count])->values()->all()
        );

        if ($show > 0) {
            $this->newLine();
            $this->table(
                ['id', 'event_id', 'kind', 'wc_status', 'created', 'order', 'order status', 'proposal'],
                $proposals->take($show)->map(fn ($row) => [
                    $row['id'],
                    $row['event_id'],
                    $row['kind'],
                    $row['wc_status'] ?? '—',
                    $row['created_at'],
                    $row['order_row_id'] ? '#'.$row['woocommerce_number'] : '—',
                    $row['order_status'] ?? '—',
                    $row['proposal'],
                ])->all()
            );
        }

        $jsonPath = $this->option('json');

        if (is_string($jsonPath) && $jsonPath !== '') {
            file_put_contents($jsonPath, json_encode([
                'generated_at' => now()->toIso8601String(),
                'organization_id' => $orgId,
                'apply' => $apply,
                'summary' => $summary,
                'proposals' => $proposals->map(fn ($row) => [
                    ...$row,
                    'processed_at' => $row['processed_at']?->toIso8601String(),
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            $this->line('JSON proposal: '.$jsonPath);
        }

        $closeable = $proposals->whereNotIn('proposal', ['review'])->count();
        $review = $proposals->where('proposal', 'review')->count();

        if (! $apply) {
            $this->newLine();
            $this->line("Would close: {$closeable} · left for review: {$review}");
            $this->comment('No rows were written. Re-run with --apply to close the proposed rows.');

            return self::SUCCESS;
        }

        $closed = 0;

        foreach ($proposals as $proposal) {
            if ($proposal['proposal'] === 'review') {
                continue;
            }

            $updated = DB::table('sp_webhook_events')
                ->where('id', $proposal['id'])
                ->where('status', 'received')
                ->update([
                    'status' => $proposal['proposal'],
                    'processed_at' => $proposal['processed_at'] ?? now(),
                    'processing_error' => $proposal['note'],
                    'updated_at' => now(),
                ]);

            $closed += $updated;
        }

        $this->newLine();
        $this->info("Closed: {$closed} · left for review: {$review}");
        $this->line('No payload was replayed; no order, component, or ledger row was touched.');
        $this->line('Re-run: php artisan audit:reservations and the Phase 1 audit.');

        return self::SUCCESS;
    }

    /**
     * @return array{id:int,event_id:string,kind:string,wc_status:?string,created_at:?string,order_row_id:?int,woocommerce_number:?string,order_status:?string,proposal:string,note:string,processed_at:?Carbon}
     */
    private function propose(object $row): array
    {
        $eventId = (string) $row->event_id;
        $kind = 'plugin';

        if (str_starts_with($eventId, 'reprocess-')) {
            $kind = 'reprocess';
        } elseif (str_starts_with($eventId, 'delete-')) {
            $kind = 'delete_artifact';
        } elseif (str_starts_with($eventId, 'ghost-release-')) {
            $kind = 'ghost_release';
        }

        $createdAt = $row->created_at ? Carbon::parse($row->created_at) : null;
        $proposal = 'review';
        $note = 'review: latest received event for the order; left untouched';
        $processedAt = null;

        if ($kind === 'reprocess') {
            $proposal = 'processed';
            $note = 'backfill: manual reprocess; engine ran; status was not persisted before the code fix';
            $processedAt = $createdAt;
        } elseif ($kind === 'delete_artifact') {
            $proposal = 'noop';
            $note = 'backfill: historical portal delete artifact; order removed; not replayed';
            $processedAt = $createdAt;
        } elseif ($kind === 'ghost_release') {
            $proposal = 'processed';
            $note = 'backfill: ghost release; engine ran';
            $processedAt = $createdAt;
        } elseif ($row->order_row_id === null) {
            $proposal = 'noop';
            $note = 'backfill: order no longer in the portal; not replayed';
            $processedAt = now();
        } elseif ((string) $row->last_webhook_event_id !== $eventId) {
            // A later event (or a later order update) already superseded this one.
            $orderUpdated = $row->order_updated_at ? Carbon::parse($row->order_updated_at) : null;
            $superseded = $orderUpdated !== null && $createdAt !== null && $orderUpdated->greaterThan($createdAt);

            if ($superseded || $row->last_webhook_event_id !== null) {
                $proposal = 'noop';
                $note = 'backfill: superseded by a later order event/update; not replayed';
                $processedAt = now();
            }
        }

        return [
            'id' => (int) $row->id,
            'event_id' => $eventId,
            'kind' => $kind,
            'wc_status' => $row->wc_status,
            'created_at' => $row->created_at,
            'order_row_id' => $row->order_row_id !== null ? (int) $row->order_row_id : null,
            'woocommerce_number' => $row->woocommerce_number,
            'order_status' => $row->order_status,
            'proposal' => $proposal,
            'note' => $note,
            'processed_at' => $processedAt,
        ];
    }
}
