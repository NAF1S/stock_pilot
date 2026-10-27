<?php

namespace App\Console\Commands;

use App\Services\ReservationAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Correct reservation cache/ledger drift against the authoritative active
 * order rows.
 *
 * For every selected mismatch:
 *   - the entity cache is set to the active-row total;
 *   - if the ledger differs, a new append-only sync_fix movement is added
 *     (ref_type=reservation_repair) for the exact delta;
 *   - the change is recorded in sp_reservation_repairs.
 *
 * Dry-run by default. Existing movements/components are never edited.
 */
class RepairReservations extends Command
{
    private const EPSILON = 0.00005;

    protected $signature = 'reservations:repair
        {--org= : Restrict to one organization id}
        {--type=both : item, product, or both}
        {--ids= : Comma-separated entity ids}
        {--apply : Write the corrections (default is a dry-run report)}
        {--reason= : Reason stored on the repair audit rows}';

    protected $description = 'Correct reserved-quantity cache/ledger drift against active order rows (dry-run by default)';

    public function handle(ReservationAudit $audit): int
    {
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;
        $type = (string) $this->option('type');
        $apply = (bool) $this->option('apply');
        $reason = (string) ($this->option('reason') ?: 'Reconcile reserved quantity with active order rows');

        if (! in_array($type, ['item', 'product', 'both'], true)) {
            $this->error('--type must be item, product, or both.');

            return self::FAILURE;
        }

        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        $report = $audit->run($orgId);

        $candidates = [];

        foreach (['item' => 'items', 'product' => 'products'] as $kind => $section) {
            if ($type !== 'both' && $type !== $kind) {
                continue;
            }

            foreach ($report[$section]['rows'] as $row) {
                if ($row['flags'] === []) {
                    continue;
                }

                if ($ids->isNotEmpty() && ! $ids->contains($row['id'])) {
                    continue;
                }

                $candidates[] = ['kind' => $kind, 'row' => $row];
            }
        }

        if ($candidates === []) {
            $this->info('No mismatches matched the filters.');

            return self::SUCCESS;
        }

        $this->info($apply ? 'Reservation repair — APPLY mode.' : 'Reservation repair — DRY RUN.');
        $this->line('Snapshot: '.$report['snapshot_at']);
        $this->line('Candidates: '.count($candidates));
        $this->newLine();

        $this->table(
            ['type', 'id', 'org', 'name', 'cache', 'rows', 'ledger', 'new cache', 'ledger delta', 'new ledger'],
            collect($candidates)->map(function (array $candidate): array {
                $row = $candidate['row'];
                $delta = round($row['active_rows'] - $row['ledger'], 4);

                return [
                    $candidate['kind'],
                    $row['id'],
                    $row['organization_id'],
                    Str::limit((string) $row['name'], 32),
                    $this->fmt($row['cache']),
                    $this->fmt($row['active_rows']),
                    $this->fmt($row['ledger']),
                    $this->fmt($row['active_rows']),
                    ($delta >= 0 ? '+' : '').$this->fmt($delta),
                    $this->fmt($row['active_rows']),
                ];
            })->all()
        );

        if (! $apply) {
            $this->comment('Dry run — nothing written. Re-run with --apply to correct these rows.');

            return self::SUCCESS;
        }

        $repaired = 0;
        $noop = 0;
        $missing = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            try {
                $result = $this->repair($candidate['kind'], (int) $candidate['row']['id'], $reason);

                match ($result) {
                    'repaired' => $repaired++,
                    'noop' => $noop++,
                    'missing' => $missing++,
                    default => null,
                };

                $this->line(sprintf(
                    '%s #%d: %s',
                    ucfirst($candidate['kind']),
                    $candidate['row']['id'],
                    $result
                ));
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->error("{$candidate['kind']} #{$candidate['row']['id']} failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Repaired: {$repaired} · no-op: {$noop} · missing: {$missing}".($failed ? " · failed: {$failed}" : ''));
        $this->line('Existing movements and component rows were not edited; corrections are new ledger rows.');
        $this->line('Re-run: php artisan audit:reservations --org='.($orgId ?? 'all'));

        return self::SUCCESS;
    }

    private function repair(string $kind, int $id, string $reason): string
    {
        return DB::transaction(function () use ($kind, $id, $reason): string {
            $table = $kind === 'item' ? 'sp_items' : 'sp_products';
            $entity = DB::table($table)->where('id', $id)->lockForUpdate()->first();

            if (! $entity) {
                return 'missing';
            }

            $organizationId = (int) $entity->organization_id;
            $activeRows = $this->activeRows($kind, $organizationId, $id);
            $ledger = $this->ledger($kind, $organizationId, $id);
            $cache = round((float) $entity->quantity_reserved, 4);
            $delta = round($activeRows - $ledger, 4);

            $cacheChanged = abs($cache - $activeRows) > self::EPSILON;
            $ledgerChanged = abs($delta) > self::EPSILON;

            if (! $cacheChanged && ! $ledgerChanged) {
                return 'noop';
            }

            $movementId = null;

            if ($ledgerChanged) {
                $movementId = DB::table('sp_item_movements')->insertGetId([
                    'organization_id' => $organizationId,
                    'item_id' => $kind === 'item' ? $id : null,
                    'product_id' => $kind === 'product' ? $id : null,
                    'movement_type' => 'sync_fix',
                    'qty_delta' => 0,
                    'reserved_delta' => $delta,
                    'on_hand_after' => $entity->quantity_on_hand,
                    'reserved_after' => $activeRows,
                    'ref_type' => 'reservation_repair',
                    'ref_id' => null,
                    'reversal_of' => null,
                    'cost_price_at_movement' => $kind === 'item' ? ($entity->cost_price ?? 0) : ($entity->cost ?? 0),
                    'reason' => $reason,
                    'created_at' => now(),
                    'occurred_at' => now(),
                ]);
            }

            if ($cacheChanged) {
                DB::table($table)->where('id', $id)->update(['quantity_reserved' => $activeRows]);
            }

            DB::table('sp_reservation_repairs')->insert([
                'organization_id' => $organizationId,
                'entity_type' => $kind,
                'entity_id' => $id,
                'old_cache' => $cache,
                'new_cache' => $activeRows,
                'ledger_before' => $ledger,
                'ledger_after' => $activeRows,
                'ledger_delta' => $delta,
                'movement_id' => $movementId,
                'reason' => $reason,
                'repaired_by' => auth()->id(),
                'repaired_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 'repaired';
        });
    }

    private function activeRows(string $kind, int $organizationId, int $id): float
    {
        $query = DB::table('sp_order_item_components')
            ->where('organization_id', $organizationId)
            ->where('reserved_quantity', '>', 0);

        if ($kind === 'item') {
            $query->where('item_id', $id);
        } else {
            $query->where(function ($q) use ($id): void {
                $q->where('component_product_id', $id)
                    ->orWhere(function ($legacy) use ($id): void {
                        $legacy->where('product_id', $id)
                            ->whereNull('item_id')
                            ->whereNull('component_product_id');
                    });
            });
        }

        return round((float) $query->sum('reserved_quantity'), 4);
    }

    private function ledger(string $kind, int $organizationId, int $id): float
    {
        return round((float) DB::table('sp_item_movements')
            ->where('organization_id', $organizationId)
            ->where($kind === 'item' ? 'item_id' : 'product_id', $id)
            ->sum('reserved_delta'), 4);
    }

    private function fmt(float $value): string
    {
        $rounded = round($value, 4);

        return rtrim(rtrim(number_format($rounded, 4, '.', ''), '0'), '.') ?: '0';
    }
}
