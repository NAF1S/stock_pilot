<?php

namespace App\Console\Commands;

use App\Services\ReservationAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only reservation reconciliation report.
 *
 * Compares the cached reserved quantity of every item/product against the
 * active component rows and the append-only ledger. Writes nothing to the
 * database; --json / --csv only write report files.
 */
class AuditReservations extends Command
{
    protected $signature = 'audit:reservations
        {--org= : Restrict to one organization id}
        {--all : Include entities where all three sources agree}
        {--json= : Write the full report to a JSON file}
        {--csv= : Write the reported rows to a CSV file}
        {--detail= : Show order/ledger detail for item:ID or product:ID}';

    protected $description = 'Read-only audit: cached reserved vs active reservation rows vs ledger (items and products)';

    public function handle(ReservationAudit $audit): int
    {
        $orgId = $this->option('org') !== null ? (int) $this->option('org') : null;

        if ((string) $this->option('detail') !== '') {
            return $this->showDetail($audit, (string) $this->option('detail'), $orgId);
        }

        $report = $audit->run($orgId);

        $this->info('Reservation reconciliation audit — READ ONLY');
        $this->line('Snapshot: '.$report['snapshot_at']);
        $this->line("Items scanned: {$report['items']['scanned']} · mismatched: {$report['items']['mismatched']}");
        $this->line("Products scanned: {$report['products']['scanned']} · mismatched: {$report['products']['mismatched']}");

        $this->line(sprintf(
            'Orphans: items %d row(s) / %s reserved · products %d row(s) / %s reserved',
            $report['orphans']['items']['entries'],
            $this->fmt($report['orphans']['items']['reserved']),
            $report['orphans']['products']['entries'],
            $this->fmt($report['orphans']['products']['reserved']),
        ));

        $all = (bool) $this->option('all');
        $tableRows = [];

        foreach (['item' => $report['items'], 'product' => $report['products']] as $kind => $section) {
            foreach ($section['rows'] as $row) {
                if (! $all && $row['flags'] === []) {
                    continue;
                }

                $tableRows[] = [
                    $kind,
                    $row['id'],
                    $row['organization_id'],
                    Str::limit((string) $row['name'], 34),
                    $this->fmt($row['cache']),
                    $this->fmt($row['active_rows']),
                    $this->fmt($row['ledger']),
                    $this->fmt($row['cache_minus_rows']),
                    $this->fmt($row['cache_minus_ledger']),
                    $this->fmt($row['rows_minus_ledger']),
                    $row['active_order_count'],
                    implode(', ', $row['flags']) ?: 'ok',
                ];
            }
        }

        if ($tableRows === []) {
            $this->newLine();
            $this->info('No mismatches found.');
        } else {
            $this->newLine();
            $this->table(
                ['type', 'id', 'org', 'name', 'cache', 'rows', 'ledger', 'cache−rows', 'cache−ledger', 'rows−ledger', 'orders', 'flags'],
                $tableRows
            );
        }

        if ($this->option('json') !== null) {
            $this->writeReport((string) $this->option('json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($this->option('csv') !== null) {
            $this->writeCsv((string) $this->option('csv'), $report);
        }

        $this->newLine();
        $this->line('No database rows were modified.');

        return self::SUCCESS;
    }

    private function showDetail(ReservationAudit $audit, string $detail, ?int $orgId): int
    {
        [$kind, $id] = array_pad(explode(':', $detail, 2), 2, null);

        if (! in_array($kind, ['item', 'product'], true) || ! is_numeric($id)) {
            $this->error('Use --detail=item:ID or --detail=product:ID.');

            return self::FAILURE;
        }

        $id = (int) $id;
        $entity = $kind === 'item'
            ? DB::table('sp_items')->where('id', $id)->first(['id', 'organization_id', 'name', 'sku', 'quantity_reserved'])
            : DB::table('sp_products')->where('id', $id)->first(['id', 'organization_id', 'name', 'sku', 'quantity_reserved']);

        if (! $entity) {
            $this->error("{$kind} #{$id} not found.");

            return self::FAILURE;
        }

        $data = $audit->detail($kind, $id, $orgId);

        $this->info(ucfirst($kind)." #{$id} — {$entity->name} (org {$entity->organization_id})");
        $this->line('Cached reserved: '.$this->fmt((float) $entity->quantity_reserved));

        $this->newLine();
        $this->line('Active reservation rows:');
        $this->table(
            ['order', 'status', 'reservation', 'reserved', 'total', 'component', 'reserved at'],
            collect($data['orders'])->map(fn ($row) => [
                '#'.$row->woocommerce_number,
                $row->status,
                $row->reservation_state,
                $this->fmt((float) $row->reserved_quantity),
                $this->fmt((float) $row->total_quantity),
                $row->component_status,
                (string) ($row->reserved_at ?? '—'),
            ])->all()
        );

        $this->newLine();
        $this->line('Ledger by movement type:');
        $this->table(
            ['type', 'entries', 'reserved_delta', 'qty_delta'],
            collect($data['ledger'])->map(fn ($row) => [
                $row->movement_type,
                $row->entries,
                $this->fmt((float) $row->reserved_delta),
                $this->fmt((float) $row->qty_delta),
            ])->all()
        );

        return self::SUCCESS;
    }

    private function writeReport(string $path, string $contents): void
    {
        $this->ensureDirectory($path);
        file_put_contents($path, $contents.PHP_EOL);
        $this->line("JSON report: {$path}");
    }

    /** @param array<string, mixed> $report */
    private function writeCsv(string $path, array $report): void
    {
        $this->ensureDirectory($path);

        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->warn("Could not write CSV: {$path}");

            return;
        }

        fputcsv($handle, [
            'type', 'id', 'organization_id', 'name', 'sku', 'cache', 'active_rows', 'ledger',
            'cache_minus_rows', 'cache_minus_ledger', 'rows_minus_ledger',
            'active_row_count', 'active_order_count', 'flags',
        ]);

        foreach (['item' => $report['items'], 'product' => $report['products']] as $kind => $section) {
            foreach ($section['rows'] as $row) {
                fputcsv($handle, [
                    $kind,
                    $row['id'],
                    $row['organization_id'],
                    $row['name'],
                    $row['sku'],
                    $row['cache'],
                    $row['active_rows'],
                    $row['ledger'],
                    $row['cache_minus_rows'],
                    $row['cache_minus_ledger'],
                    $row['rows_minus_ledger'],
                    $row['active_row_count'],
                    $row['active_order_count'],
                    implode('|', $row['flags']),
                ]);
            }
        }

        fclose($handle);
        $this->line("CSV report: {$path}");
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if ($directory !== '' && ! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function fmt(float $value): string
    {
        $rounded = round($value, 4);

        return rtrim(rtrim(number_format($rounded, 4, '.', ''), '0'), '.') ?: '0';
    }
}
