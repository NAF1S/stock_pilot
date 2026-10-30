<?php

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Services\ProductSyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phase4Store(Organization $org, string $name, string $key): StoreConnection
{
    return StoreConnection::create([
        'organization_id' => $org->id,
        'name' => $name,
        'store_url' => 'https://'.$key.'.example.com',
        'api_key' => 'sp_'.$key,
        'api_secret' => 'secret_'.$key.'_012345678901234567890123456789',
    ]);
}

test('product sync stores the woo slug and permalink for traffic joins', function () {
    $org = Organization::create(['name' => 'Slug Org']);
    $store = phase4Store($org, 'Slug Store', 'slug');

    app(ProductSyncProcessor::class)->syncProduct([
        'id' => 7777,
        'name' => 'Slug Product',
        'slug' => 'slug-product',
        'permalink' => 'https://slug.example.com/product/slug-product/',
        'type' => 'simple',
        'status' => 'publish',
        'price' => 10,
        'sku' => 'SLUG-1',
    ], $store);

    $product = Product::where('woo_product_id', 7777)->first();

    expect($product)->not->toBeNull();
    expect($product->slug)->toBe('slug-product');
    expect($product->permalink)->toBe('https://slug.example.com/product/slug-product/');
    expect($product->payload['slug'])->toBe('slug-product');
});

test('two stores in one organization can share a woocommerce order id', function () {
    $org = Organization::create(['name' => 'Multi Store Org']);
    $storeA = phase4Store($org, 'Store A', 'storea');
    $storeB = phase4Store($org, 'Store B', 'storeb');

    $base = [
        'organization_id' => $org->id,
        'woocommerce_id' => 100,
        'woocommerce_number' => '100',
        'status' => 'processing',
        'reservation_state' => 'none',
        'total' => 0,
    ];

    $first = Order::create($base + ['store_id' => $storeA->id]);
    $second = Order::create($base + ['store_id' => $storeB->id]);

    expect($first->id)->not->toBe($second->id);
    expect(Order::where('organization_id', $org->id)->where('woocommerce_id', 100)->count())->toBe(2);
});
