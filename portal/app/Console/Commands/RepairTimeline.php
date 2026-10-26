<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0B backlog repair: backfill `sp_item_movements.occurred_at` from the
 * order's own WooCommerce timestamps.
 *
 * What this does NOT do:
 *   - it never touches quantities, `created_at`, or any economic data;
 *   - it never invents a time: rows with no derivable source stay NULL and
 *     reads fall back to `created_at`;
 *   - it does not rewrite historical status events (intermediate transitions
 *     cannot be recovered from the payload).
 *
 * Safe by design:
 *   - dry-run by default; pass --apply to write.
 *   - every applied change is recorded in `sp_timeline_repairs`.
 */
class RepairTimeline extends Command
{
    protected $signature = 'ml:repair-timeline
        {--apply : Write the backfilled occurred_at values (default is a dry-run report)}
        {--org= : Restrict to one organization id}
        {--limit=200 : Max rows to write per batch}
        {--show=10 : How many sample rows to print}';

    protected $description = 'Backfill sp_item_movements.occurred_at from order event times (Phase 0B)';

    public function handle(): int
    {
        if (! Schema::hasTable('sp_item_movements') || ! Schema::hasColumn('sp_item_movements', 'occurred_at')) {
            $this->error('sp_item_movements.occurred_at is missing. Run the migrations first (AutoMigrate applies them on the next request).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;
        $limit = max(1, (int) $this->option('limit'));
        $show = max(0, (int) $this->option('show'));

        $this->info($apply
            ? 'Timeline repair — APPLY mode (occurred_at will be written; quantities are untouched).'
            : 'Timeline repair — DRY RUN. Add --apply to write.');

        $this->checkClocks();

        $base = DB::table('sp_item_movements as m')
            ->join('sp_orders as o', function ($join): void {
                $join->on('o.id', '=', 'm.ref_id')
                    ->where('m.ref_type', '=', 'order');
            })
            ->whereNull('m.occurred_at')
            ->whereIn('m.movement_type', ['reserve', 'confirm', 'release', 'return'])
            ->when($orgId !== null, fn ($q) => $q->where('m.organization_id', $orgId))
            ->select([
                'm.id',
                'm.organization_id',
                'm.movement_type',
                'm.created_at',
                'o.order_created_at',
                'o.order_updated_at',
                'o.woocommerce_number',
            ])
            ->orderBy('m.id');

        $scanned = 0;
        $repairable = 0;
        $applied = 0;
        $skipped = 0;
        $samples = [];
        $byType = [];

        foreach ($base->cursor() as $row) {
            $scanned++;
            $byType[$row->movement_type] = ($byType[$row->movement_type] ?? 0) + 1;

            [$candidate, $source] = $this->candidate($row);

            if (! $candidate) {
                $skipped++;
                continue;
            }

            $repairable++;

            if (count($samples) < $show) {
                $samples[] = [
                    $row->id,
                    $row->movement_type,
                    '#'.$row->woocommerce_number,
                    (string) $row->created_at,
                    $candidate->toDateTimeString(),
                    $source,
                ];
            }

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($row, $candidate, $source): void {
                DB::table('sp_item_movements')
                    ->where('id', $row->id)
                    ->update(['occurred_at' => $candidate]);

                DB::table('sp_timeline_repairs')->insert([
                    'organization_id' => $row->organization_id,
                    'movement_id' => $row->id,
                    'old_created_at' => $row->created_at,
                    'new_occurred_at' => $candidate,
                    'source' => $source,
                    'status' => 'applied',
                    'note' => 'occurred_at backfilled by ml:repair-timeline',
                    'repaired_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $applied++;
        }

        $this->newLine();
        $this->line('Scanned (no occurred_at, order-linked): '.$scanned);
        $this->line('Repairable: '.$repairable);
        $this->line($apply ? 'Applied: '.$applied : 'Would apply: '.$repairable);
        $this->line('Skipped (no derivable source): '.$skipped);

        if ($byType !== []) {
            $this->line('By movement type: '.collect($byType)
                ->map(fn ($n, $t) => "{$t}={$n}")
                ->implode(', '));
        }

        if ($samples !== []) {
            $this->newLine();
            $this->table(
                ['id', 'type', 'order', 'created_at (record)', 'occurred_at (event)', 'source'],
                $samples
            );
        }

        $statusEvents = DB::table('sp_order_status_events')->count();
        $this->newLine();
        $this->line("Note: {$statusEvents} historical status-event rows keep their processing timestamp —");
        $this->line('intermediate transitions cannot be recovered from the payload. New events use occurred_at.');

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run complete. Re-run with --apply to write the changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Surface app-vs-DB clock drift. Both should be UTC; a mismatch is what
     * produced the historical −6h skew, so make it visible before repairing.
     */
    private function checkClocks(): void
    {
        $appNow = now()->toDateTimeString();
        $dbNow = null;

        try {
            $dbNow = DB::selectOne('select current_timestamp as db_now')->db_now ?? null;
        } catch (\Throwable) {
            $dbNow = null;
        }

        $this->line("App time (config/app.php timezone): {$appNow}");
        $this->line('DB current_timestamp: '.($dbNow ?? 'unknown'));

        if ($dbNow !== null) {
            $drift = abs(Carbon::parse($dbNow)->diffInSeconds(now(), false));

            if ($drift > 300) {
                $this->warn("Clock mismatch: app and DB differ by ~{$drift}s. Pin the DB session timezone to UTC before trusting repairs.");
            }
        }

        $this->newLine();
    }

    /**
     * Derive the business event time for a movement from the order payload.
     *
     * @return array{0: \Illuminate\Support\Carbon|null, 1: string|null}
     */
    private function candidate(object $row): array
    {
        $createdAt = $this->parse($row->created_at);

        if (! $createdAt) {
            return [null, null];
        }

        if ($row->movement_type === 'reserve') {
            $candidate = $this->parse($row->order_created_at);
            $source = 'order_created_at';
        } else {
            $candidate = $this->parse($row->order_updated_at) ?? $this->parse($row->order_created_at);
            $source = $this->parse($row->order_updated_at) ? 'order_updated_at' : 'order_created_at';
        }

        if (! $candidate) {
            return [null, null];
        }

        $now = now();

        // Reject impossible values rather than poisoning the series.
        if ($candidate->greaterThan($now->copy()->addMinutes(5)) || $candidate->lessThan($now->copy()->subDays(400))) {
            return [null, null];
        }

        // The event cannot have happened after the portal recorded it.
        if ($candidate->greaterThan($createdAt)) {
            $candidate = $createdAt->copy();
            $source .= ' (clamped to record time)';
        }

        return [$candidate, $source];
    }

    private function parse($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
