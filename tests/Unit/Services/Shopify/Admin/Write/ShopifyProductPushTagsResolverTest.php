<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\Shopify\Admin\Write\ShopifyProductPushTagsResolver;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use Tests\TestCase;

uses(TestCase::class)->use(Illuminate\Foundation\Testing\RefreshDatabase::class);

function pushTagsTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'MT-02',
        'description' => 'Madworks Masking Tape 2mm',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('merges storefront tags with existing mirror tags and strips legacy on push', function (): void {
    $productGid = 'gid://shopify/Product/88001';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'mt-02',
        'title' => 'Tape',
        'status' => 'ACTIVE',
        'payload_json' => [
            'tags' => ['supplies', 'Others', 'ts:dept:tapes', 'custom-campaign'],
        ],
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/88002',
        'product_gid' => $productGid,
        'sku' => 'MT-02',
    ]);

    $resolver = app(ShopifyProductPushTagsResolver::class);
    $tags = $resolver->tagsForProductSet(
        pushTagsTestProduct(['sku' => 'MT-02']),
        $productGid,
        true,
        false,
    );

    expect($tags)->toContain('custom-campaign', 'ts:dept:tapes', 'ts:tape:masking', 'ts:tape:width:2')
        ->and($tags)->not->toContain('supplies', 'Others');
});

it('returns null for unclassified updates so Shopify tags stay unchanged', function (): void {
    $resolver = app(ShopifyProductPushTagsResolver::class);
    $tags = $resolver->tagsForProductSet(
        pushTagsTestProduct([
            'sku' => 'ORPHAN-1',
            'main_type' => 'supplies',
            'type' => 'Others',
        ]),
        'gid://shopify/Product/88003',
        true,
        false,
    );

    expect($tags)->toBeNull();
});

it('merges latest arrival tag on unclassified info updates', function (): void {
    $productGid = 'gid://shopify/Product/88004';
    ShopifyProduct::query()->create([
        'gid' => $productGid,
        'handle' => 'pg-unleashed',
        'title' => 'PG Unleashed',
        'status' => 'ACTIVE',
        'payload_json' => [
            'tags' => ['PG', 'model kit'],
        ],
    ]);
    ShopifyProductVariant::query()->create([
        'gid' => 'gid://shopify/ProductVariant/88005',
        'product_gid' => $productGid,
        'sku' => '5069191',
    ]);

    $resolver = app(ShopifyProductPushTagsResolver::class);
    $tags = $resolver->tagsForProductSet(
        pushTagsTestProduct([
            'sku' => '5069191',
            'main_type' => 'PG',
            'type' => 'model kit',
            'latest_arrival' => true,
        ]),
        $productGid,
        true,
        true,
    );

    expect($tags)->toContain('PG', 'model kit', 'latest arrival');
});

it('classifies MS-23 as scribing and MS-27 as cutting knife', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);

    $ms23 = $classifier->classify(pushTagsTestProduct([
        'sku' => 'MS-23',
        'description' => 'Tungsten Steel Needle',
        'main_type' => 'tools',
        'type' => 'Scribing',
    ]));

    $ms27 = $classifier->classify(pushTagsTestProduct([
        'sku' => 'MS-27',
        'description' => 'Stedi Tungsten Steel Push Knife 1.5 mm',
        'main_type' => 'supplies',
        'type' => 'Scribing',
    ]));

    expect($ms23->department)->toBe('scribing')
        ->and($ms23->storefrontTags)->toContain('ts:dept:scribing', 'ts:scribing:type:needle')
        ->and($ms27->department)->toBe('cutting')
        ->and($ms27->storefrontTags)->toContain('ts:dept:cutting', 'ts:cut:knife', 'ts:cut:style:pen-knife');
});

it('classifies MS-JD3H aluminum sanding plate as sanding board-plate', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);

    $result = $classifier->classify(pushTagsTestProduct([
        'sku' => 'MS-JD3H',
        'description' => 'Aluminum Sanding Plate (Black) 3 different size',
        'main_type' => 'supplies',
        'type' => 'TOOLS',
    ]));

    expect($result->department)->toBe('sanding')
        ->and($result->storefrontTags)->toContain('ts:dept:sanding', 'ts:sand:type:board-plate');
});
