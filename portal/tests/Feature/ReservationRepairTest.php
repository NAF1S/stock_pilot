<?php

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Organization;
use App\Models\Product;
use App\Services\ReservationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function repairFixture(): array
{
    $org = Organization::create(['name' => 'Repair Org']);

    $itemA = Item::create([
        'organization_id' => $org->id,
        'name' => 'Repair Item A',
        'sku' => 'REP-A',
        'unit' => 'pcs',
        'quantity_on_hand' => 10,
        'quantity_reserved' => 2,
        'cost_price' => 1,
    ]);

    $itemB = Item::create([
        'organization_id' => $org->id,
        'name' => 'Repair Item B',
        'sku' => 'REP-B',
        'unit' => 'pcs',
        'quantity_on_hand' => 10,
        'quantity_reserved' => 2,
        'cost_price' => 1,
    ]);

    $product = Product::create([
        'organization_id' => $org->id,
        'name' => 'Repair Product',
        'sku' => 'REP-P',
        'source' => 'manual',
        'quantity_on_hand' => 10,
        'quantity_reserved' => 0,
        'cost' => 2,
    ]);

    // Item A carries the historical -3 ledger drift; item B only has cache drift.
    ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $itemA->id,
        'movement_type' => 'sync_fix',
        'qty_delta' => 0,
        'reserved_delta' => -3,
        'on_hand_after' => 10,
        'reserved_after' => 2,
        'ref_type' => 'sync_fix',
        'reason' => 'historical cache reconciliation',
    ]);

    return [$org, $itemA, $itemB, $product];
}

test('reservation repair is dry-run by default', function () {
    [$org, $itemA, $itemB] = repairFixture();

    $this->artisan('reservations:repair', [
        '--org' => $org->id,
        '--type' => 'item',
        '--ids' => "{$itemA->id},{$itemB->id}",
    ])->assertSuccessful();

    expect($itemA->fresh()->quantity_reserved)->toBe(2.0);
    expect($itemB->fresh()->quantity_reserved)->toBe(2.0);
    expect(ItemMovement::where('ref_type', 'reservation_repair')->count())->toBe(0);
    expect(DB::table('sp_reservation_repairs')->count())->toBe(0);
});

test('reservation repair corrects cache and appends the ledger delta', function () {
    [$org, $itemA, $itemB, $product] = repairFixture();

    $this->artisan('reservations:repair', [
        '--org' => $org->id,
        '--type' => 'item',
        '--ids' => "{$itemA->id},{$itemB->id}",
        '--apply' => true,
    ])->assertSuccessful();

    expect($itemA->fresh()->quantity_reserved)->toBe(0.0);
    expect($itemB->fresh()->quantity_reserved)->toBe(0.0);
    expect($product->fresh()->quantity_reserved)->toBe(0.0);

    $repairMovement = ItemMovement::where('ref_type', 'reservation_repair')->first();
    expect($repairMovement)->not->toBeNull();
    expect($repairMovement->item_id)->toBe($itemA->id);
    expect((float) $repairMovement->reserved_delta)->toBe(3.0);
    expect((float) $repairMovement->reserved_after)->toBe(0.0);

    // Item B only needed the cache correction, so it has no repair movement.
    expect(ItemMovement::where('ref_type', 'reservation_repair')->where('item_id', $itemB->id)->count())->toBe(0);

    expect(DB::table('sp_reservation_repairs')->count())->toBe(2);

    $report = app(ReservationAudit::class)->run($org->id);
    expect($report['items']['mismatched'])->toBe(0);
    expect($report['products']['mismatched'])->toBe(0);
});

test('reservation repair is idempotent', function () {
    [$org, $itemA, $itemB] = repairFixture();

    $this->artisan('reservations:repair', [
        '--org' => $org->id,
        '--type' => 'item',
        '--ids' => "{$itemA->id},{$itemB->id}",
        '--apply' => true,
    ])->assertSuccessful();

    $movementCount = ItemMovement::where('ref_type', 'reservation_repair')->count();
    $repairCount = DB::table('sp_reservation_repairs')->count();

    $this->artisan('reservations:repair', [
        '--org' => $org->id,
        '--type' => 'item',
        '--ids' => "{$itemA->id},{$itemB->id}",
        '--apply' => true,
    ])->assertSuccessful();

    expect(ItemMovement::where('ref_type', 'reservation_repair')->count())->toBe($movementCount);
    expect(DB::table('sp_reservation_repairs')->count())->toBe($repairCount);
});
