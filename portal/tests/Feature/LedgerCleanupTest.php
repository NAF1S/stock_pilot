<?php

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cleanupOrg(): Organization
{
    return Organization::create(['name' => 'Cleanup Org']);
}

function cleanupMovement(Organization $org, Item $item, string $type, float $qty, float $reserved, int $orderId, int $seq): void
{
    ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'movement_type' => $type,
        'qty_delta' => $qty,
        'reserved_delta' => $reserved,
        'on_hand_after' => 0,
        'reserved_after' => 0,
        'ref_type' => 'order',
        'ref_id' => $orderId,
        'reason' => "seq {$seq}",
    ]);
}

test('dry-run reports duplicate-confirm phantom rows without changing anything', function () {
    $org = cleanupOrg();
    $item = Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'quantity_on_hand' => 46]);

    cleanupMovement($org, $item, 'confirm', -2, -2, 1, 1);
    cleanupMovement($org, $item, 'confirm', -2, -2, 1, 2); // phantom duplicate

    $this->artisan('ledger:cleanup')->assertExitCode(0);

    // Nothing deleted, nothing changed.
    expect(ItemMovement::where('item_id', $item->id)->count())->toBe(2);
    expect($item->fresh()->quantity_on_hand)->toBe(46.0);
});

test('apply deletes only the phantom row and restores the balance', function () {
    $org = cleanupOrg();
    $item = Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'quantity_on_hand' => 46]);

    $first = ItemMovement::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'movement_type' => 'confirm',
        'qty_delta' => -2,
        'reserved_delta' => -2,
        'on_hand_after' => 0,
        'reserved_after' => 0,
        'ref_type' => 'order',
        'ref_id' => 1,
        'reason' => 'seq 1',
    ]);
    cleanupMovement($org, $item, 'confirm', -2, -2, 1, 2); // phantom duplicate

    $this->artisan('ledger:cleanup', ['--apply' => true])->assertExitCode(0);

    // The first (legit) confirm survives; the duplicate is gone; on-hand restored 46 -> 48.
    expect(ItemMovement::where('item_id', $item->id)->where('movement_type', 'confirm')->count())->toBe(1);
    expect($item->fresh()->quantity_on_hand)->toBe(48.0);
    expect(ItemMovement::where('item_id', $item->id)->where('movement_type', 'confirm')->first()->id)->toBe($first->id);
});

test('a legitimate confirm-return-confirm cycle is left untouched', function () {
    $org = cleanupOrg();
    $item = Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'quantity_on_hand' => 48]);

    cleanupMovement($org, $item, 'confirm', -2, -2, 1, 1);
    cleanupMovement($org, $item, 'return', 2, 0, 1, 2);
    cleanupMovement($org, $item, 'confirm', -2, -2, 1, 3);

    $this->artisan('ledger:cleanup')->assertExitCode(0);

    // Ambiguous cycle (return between confirms) is NOT auto-cleaned — all rows kept.
    expect(ItemMovement::where('item_id', $item->id)->count())->toBe(3);
    expect($item->fresh()->quantity_on_hand)->toBe(48.0);
});

test('only duplicates for the same order are flagged, not separate orders', function () {
    $org = cleanupOrg();
    $item = Item::create(['organization_id' => $org->id, 'name' => 'Belt', 'unit' => 'pcs', 'quantity_on_hand' => 44]);

    // One legit confirm per order for two different orders = 2 confirms, no phantom.
    cleanupMovement($org, $item, 'confirm', -2, -2, 101, 1);
    cleanupMovement($org, $item, 'confirm', -2, -2, 102, 2);

    $this->artisan('ledger:cleanup', ['--apply' => true])->assertExitCode(0);

    expect(ItemMovement::where('item_id', $item->id)->count())->toBe(2);
    expect($item->fresh()->quantity_on_hand)->toBe(44.0);
});
