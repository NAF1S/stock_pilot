<?php

namespace App\Console\Commands;

use App\Models\ItemMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hide specific ledger movements from analytics without deleting or editing
 * them. The ledger remains complete and auditable; only the read-side
 * aggregation skips the listed rows.
 *
 * Example (a bulk add/remove batch that nets to ~0 and distorts the chart):
 *   php artisan analytics:exclude-movements --ids=2983,2984,2992 \
 *       --reason="2026-08-08 bulk adjustment correction"
 */
class ExcludeMovementsFromAnalytics extends Command
{
    protected $signature = 'analytics:exclude-movements
        {--ids= : Comma-separated movement ids}
        {--reason= : Why these movements are excluded from analytics}
        {--org= : Only exclude movements belonging to this organization id}
        {--dry-run : Show what would be excluded without writing}
        {--list : List existing analytics exclusions}';

    protected $description = 'Exclude specific ledger movements from analytics (the ledger itself is never modified)';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listExclusions();
        }

        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Pass --ids=1,2,3 (or --list to inspect current exclusions).');

            return self::FAILURE;
        }

        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;
        $reason = (string) ($this->option('reason') ?: 'Excluded from analytics');
        $dryRun = (bool) $this->option('dry-run');

        $movements = ItemMovement::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->when($orgId !== null, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('id')
            ->get();

        $missing = $ids->diff($movements->pluck('id')->map(fn ($id) => (int) $id));

        if ($movements->isEmpty()) {
            $this->error('No movements found for the supplied ids.');

            return self::FAILURE;
        }

        $this->table(
            ['id', 'org', 'type', 'ref', 'qty_delta', 'created_at', 'occurred_at'],
            $movements->map(fn (ItemMovement $m) => [
                $m->id,
                $m->organization_id,
                $m->movement_type,
                $m->ref_type.($m->ref_id ? '#'.$m->ref_id : ''),
                (float) $m->qty_delta,
                (string) $m->created_at,
                (string) ($m->occurred_at ?? '—'),
            ])->all()
        );

        if ($missing->isNotEmpty()) {
            $this->warn('Not found: '.$missing->implode(', '));
        }

        if ($movements->contains(fn (ItemMovement $m) => $m->ref_type === 'order')) {
            $this->warn('Order-linked movements are not used by the order analytics path (it reads orders/components), so excluding them may not change the chart.');
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing written. Remove --dry-run to apply.');
            $this->newLine();
            $this->checkExisting($movements->pluck('id')->all());

            return self::SUCCESS;
        }

        $inserted = 0;

        foreach ($movements as $movement) {
            $exists = DB::table('sp_movement_exclusions')
                ->where('movement_id', $movement->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('sp_movement_exclusions')->insert([
                'organization_id' => $movement->organization_id,
                'movement_id' => $movement->id,
                'reason' => $reason,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        }

        $skipped = $movements->count() - $inserted;
        $this->info("Excluded from analytics: {$inserted} new, {$skipped} already present.");
        $this->line('Ledger rows were not modified.');

        return self::SUCCESS;
    }

    private function checkExisting(array $ids): void
    {
        $existing = DB::table('sp_movement_exclusions')
            ->whereIn('movement_id', $ids)
            ->pluck('movement_id');

        $this->line($existing->isEmpty()
            ? 'None of these ids are currently excluded.'
            : 'Already excluded: '.$existing->implode(', '));
    }

    private function listExclusions(): int
    {
        $rows = DB::table('sp_movement_exclusions as e')
            ->join('sp_item_movements as m', 'm.id', '=', 'e.movement_id')
            ->orderBy('e.id')
            ->get([
                'e.id',
                'e.organization_id',
                'e.movement_id',
                'e.reason',
                'm.movement_type',
                'm.ref_type',
                'm.qty_delta',
                'm.created_at',
                'm.occurred_at',
            ]);

        if ($rows->isEmpty()) {
            $this->line('No analytics exclusions.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'org', 'movement', 'type', 'ref', 'qty_delta', 'created_at', 'reason'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->organization_id,
                $r->movement_id,
                $r->movement_type,
                $r->ref_type,
                (float) $r->qty_delta,
                (string) $r->created_at,
                $r->reason,
            ])->all()
        );

        return self::SUCCESS;
    }
}
