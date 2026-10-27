<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill occurred_at for manual ledger rows where it was never set.
 *
 * Adjustments, initial stock and cache-reconciliation (sync_fix) rows are
 * created by an operator action, so `created_at` *is* the business event time.
 * Setting occurred_at = created_at makes that explicit; it does not change any
 * quantity, created_at, or other ledger field. Every write is recorded in
 * sp_timeline_repairs.
 *
 * Dry-run by default; --apply writes.
 */
class MovementsBackfillOccurred extends Command
{
    protected $signature = 'movements:backfill-occurred
        {--apply : Write occurred_at = created_at (default is a dry-run report)}
        {--org= : Restrict to one organization id}
        {--show=10 : How many sample rows to print}';

    protected $description = 'Backfill occurred_at=created_at for manual adjustment/initial/sync_fix movements (dry-run by default)';

    private const TYPES = ['adjustment', 'initial', 'sync_fix'];

    public function handle(): int
    {
        if (! Schema::hasTable('sp_item_movements') || ! Schema::hasColumn('sp_item_movements', 'occurred_at')) {
            $this->error('sp_item_movements.occurred_at is missing. Run the migrations first.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;
        $show = max(0, (int) $this->option('show'));

        $base = DB::table('sp_item_movements')
            ->whereNull('occurred_at')
            ->whereIn('movement_type', self::TYPES)
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId));

        $byType = (clone $base)
            ->select(
                'movement_type',
                DB::raw('count(*) as rows'),
                DB::raw('coalesce(sum(qty_delta), 0) as qty_delta'),
                DB::raw('coalesce(sum(reserved_delta), 0) as reserved_delta'),
                DB::raw('min(created_at) as first_created'),
                DB::raw('max(created_at) as last_created')
            )
            ->groupBy('movement_type')
            ->orderBy('movement_type')
            ->get();

        $total = (int) $byType->sum('rows');

        $this->info($apply
            ? 'Occurred-at backfill — APPLY mode (metadata only; quantities are untouched).'
            : 'Occurred-at backfill — DRY RUN. Add --apply to write.');

        if ($total === 0) {
            $this->info('No manual rows need backfilling.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['movement type', 'rows', 'qty delta', 'reserved delta', 'first created', 'last created'],
            $byType->map(fn ($row) => [
                $row->movement_type,
                $row->rows,
                $row->qty_delta,
                $row->reserved_delta,
                $row->first_created,
                $row->last_created,
            ])->all()
        );
        $this->line("Rows to update: {$total} (occurred_at = created_at).");

        if ($show > 0) {
            $samples = (clone $base)->orderByDesc('id')->limit($show)->get([
                'id', 'organization_id', 'movement_type', 'item_id', 'product_id', 'qty_delta', 'reserved_delta', 'created_at',
            ]);

            $this->newLine();
            $this->table(
                ['id', 'org', 'type', 'item', 'product', 'qty', 'reserved', 'created_at'],
                $samples->map(fn ($row) => [
                    $row->id,
                    $row->organization_id,
                    $row->movement_type,
                    $row->item_id ?? '—',
                    $row->product_id ?? '—',
                    $row->qty_delta,
                    $row->reserved_delta,
                    $row->created_at,
                ])->all()
            );
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run complete. Re-run with --apply to write the metadata.');

            return self::SUCCESS;
        }

        $applied = 0;
        $skipped = 0;

        foreach ((clone $base)->orderBy('id')->cursor() as $row) {
            if ($row->created_at === null) {
                $skipped++;
                continue;
            }

            $updated = DB::transaction(function () use ($row): bool {
                $updated = DB::table('sp_item_movements')
                    ->where('id', $row->id)
                    ->whereNull('occurred_at')
                    ->update(['occurred_at' => $row->created_at]);

                if ($updated === 0) {
                    return false;
                }

                DB::table('sp_timeline_repairs')->insert([
                    'organization_id' => $row->organization_id,
                    'movement_id' => $row->id,
                    'old_created_at' => $row->created_at,
                    'new_occurred_at' => $row->created_at,
                    'source' => 'created_at_backfill',
                    'status' => 'applied',
                    'note' => 'manual/seed/sync_fix row: occurred_at set to created_at',
                    'repaired_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            });

            $updated ? $applied++ : $skipped++;
        }

        $this->newLine();
        $this->info("Applied: {$applied}".($skipped ? " · skipped: {$skipped}" : ''));
        $this->line('Audit rows: sp_timeline_repairs (source=created_at_backfill).');
        $this->line('No quantities or created_at values were changed.');

        return self::SUCCESS;
    }
}
