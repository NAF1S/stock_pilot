<?php

use App\Mail\LowStockAlert;
use App\Mail\StockSummary;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockWarning;
use App\Services\LowStockNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function lowStockOrg(?string $email = 'alerts@test.dev'): Organization
{
    return Organization::create(['name' => 'Test Org', 'low_stock_email' => $email]);
}

function lowStockItem(Organization $org, float $onHand = 3, float $threshold = 10): Item
{
    return Item::create([
        'organization_id' => $org->id,
        'name' => 'Test Item',
        'unit' => 'pcs',
        'cost_price' => 5,
        'quantity_on_hand' => $onHand,
        'low_stock_threshold' => $threshold,
        'is_active' => true,
    ]);
}

test('a threshold crossing creates a warning and sends the alert email', function () {
    Mail::fake();
    $org = lowStockOrg();
    $item = lowStockItem($org);

    app(LowStockNotifier::class)->evaluateItem($item->id, $org->id);

    $warning = StockWarning::where('item_id', $item->id)->where('status', 'active')->first();
    expect($warning)->not->toBeNull()
        ->and($warning->email_sent)->toBeTrue();

    Mail::assertSent(LowStockAlert::class);
});

test('an unsent active warning is retried once the cooldown lapses', function () {
    Mail::fake();
    $org = lowStockOrg();
    $item = lowStockItem($org);

    // A previous crossing was recorded, but its email never went out and the
    // cooldown has now passed — the next evaluation must retry the send.
    StockWarning::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'warning_type' => 'low_stock',
        'status' => 'active',
        'triggered_at' => now()->subDays(2),
        'cooldown_until' => now()->subDay(),
        'email_sent' => false,
    ]);

    app(LowStockNotifier::class)->evaluateItem($item->id, $org->id);

    Mail::assertSent(LowStockAlert::class);

    $warning = StockWarning::where('item_id', $item->id)->first();
    expect($warning->email_sent)->toBeTrue()
        ->and($warning->email_sent_at)->not->toBeNull();
});

test('an already-delivered warning is not emailed again while active', function () {
    Mail::fake();
    $org = lowStockOrg();
    $item = lowStockItem($org);

    StockWarning::create([
        'organization_id' => $org->id,
        'item_id' => $item->id,
        'warning_type' => 'low_stock',
        'status' => 'active',
        'triggered_at' => now()->subDay(),
        'cooldown_until' => now()->addHours(6),
        'email_sent' => true,
    ]);

    app(LowStockNotifier::class)->evaluateItem($item->id, $org->id);

    Mail::assertNothingSent();
});

test('recovering stock clears the active warning without sending', function () {
    Mail::fake();
    $org = lowStockOrg();
    $item = lowStockItem($org, onHand: 3);

    app(LowStockNotifier::class)->evaluateItem($item->id, $org->id);
    Mail::assertSent(LowStockAlert::class);

    $item->update(['quantity_on_hand' => 50]);

    app(LowStockNotifier::class)->evaluateItem($item->id, $org->id);

    expect(StockWarning::where('item_id', $item->id)->where('status', 'active')->exists())->toBeFalse();
    Mail::assertSentCount(1);
});

test('stock:summary emails a WARNING when anything is low on stock', function () {
    Mail::fake();
    $org = lowStockOrg();
    lowStockItem($org, onHand: 2);

    $this->artisan('stock:summary')->assertExitCode(0);

    Mail::assertSent(StockSummary::class, function (StockSummary $mail) {
        return str_starts_with($mail->envelope()->subject, 'Low stock warning');
    });
});

test('stock:summary emails a REPORT when nothing is low on stock', function () {
    Mail::fake();
    $org = lowStockOrg();
    lowStockItem($org, onHand: 50);

    $this->artisan('stock:summary')->assertExitCode(0);

    Mail::assertSent(StockSummary::class, function (StockSummary $mail) {
        return str_starts_with($mail->envelope()->subject, 'Stock status report');
    });
});

test('stock:summary includes products with a threshold in the report', function () {
    Mail::fake();
    $org = lowStockOrg();
    lowStockItem($org, onHand: 50);

    Product::create([
        'organization_id' => $org->id,
        'name' => 'Belt Kit',
        'sku' => 'KIT-1',
        'source' => 'manual',
        'quantity_on_hand' => 4,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    $this->artisan('stock:summary')->assertExitCode(0);

    Mail::assertSent(StockSummary::class, function (StockSummary $mail) {
        return str_starts_with($mail->envelope()->subject, 'Low stock warning')
            && $mail->lowProducts()->count() === 1;
    });
});

test('stock:summary skips organizations without a low-stock email', function () {
    Mail::fake();
    lowStockOrg(email: null);

    $this->artisan('stock:summary')->assertExitCode(0);

    Mail::assertNothingSent();
});
