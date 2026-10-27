<?php

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function backfillFixture(): array
{
    $org = Organization::create(['name' => 'Backfill Org']);

    $item = Item::create([
        'organization_id' => $org->id,
        'name' => 'Backfill Item',
        'sku' => 'BF-1',
        'unit' => 'pcs',
        'quantity_on_hand' => 10,
        'quantity_reserved' => 0,
        'cost_price' => 1,
    ]);

    $make = fn (string $type, float $qty, ?string $occurredAt = null) => ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'movement_type' => $type,
        'qty_delta' => $qty,
        'reserved_delta' => 0,
        'on_hand_after' => 10,
        'reserved_after' => 0,
        'ref_type' => $type === 'adjustment' ? 'adjustment' : $type,
        'ref_id' => $item->id,
        'reason' => 'test',
        'occurred_at' => $occurredAt,
    ]);

    $adjustment = $make('adjustment', 5);
    $initial = $make('initial', 10);
    $syncFix = $make('sync_fix', 0);
    $confirm = $make('confirm', -1);
    $alreadySet = $make('adjustment', 1, now()->subDay()->toDateTimeString());

    return [$org, $item, $adjustment, $initial, $syncFix, $confirm, $alreadySet];
}

test('occurred-at backfill is dry-run by default', function () {
    [$org, , $adjustment, $initial, $syncFix, $confirm] = backfillFixture();

    $this->artisan('movements:backfill-occurred', ['--org' => $org->id])->assertSuccessful();

    foreach ([$adjustment, $initial, $syncFix, $confirm] as $movement) {
        expect(DB::table('sp_item_movements')->where('id', $movement->id)->value('occurred_at'))->toBeNull();
    }

    expect(DB::table('sp_timeline_repairs')->count())->toBe(0);
});

test('occurred-at backfill sets manual rows to created_at only', function () {
    [$org, , $adjustment, $initial, $syncFix, $confirm, $alreadySet] = backfillFixture();

    $alreadySetBefore = DB::table('sp_item_movements')->where('id', $alreadySet->id)->value('occurred_at');

    $this->artisan('movements:backfill-occurred', [
        '--org' => $org->id,
        '--apply' => true,
    ])->assertSuccessful();

    foreach ([$adjustment, $initial, $syncFix] as $movement) {
        $row = DB::table('sp_item_movements')->find($movement->id);
        expect($row->occurred_at)->not->toBeNull();
        expect($row->occurred_at)->toBe($row->created_at);
    }

    // Demand confirms are not manual rows and must keep their own event time.
    expect(DB::table('sp_item_movements')->where('id', $confirm->id)->value('occurred_at'))->toBeNull();

    // Already-set values are not overwritten.
    expect(DB::table('sp_item_movements')->where('id', $alreadySet->id)->value('occurred_at'))->toBe($alreadySetBefore);

    expect(DB::table('sp_timeline_repairs')->where('source', 'created_at_backfill')->count())->toBe(3);
});
