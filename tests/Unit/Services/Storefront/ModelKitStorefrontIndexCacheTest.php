<?php

declare(strict_types=1);

use App\DTOs\Storefront\ModelKitStorefrontIndexDocument;
use App\DTOs\Storefront\ModelKitStorefrontIndexRow;
use App\Jobs\Shopify\RebuildModelKitStorefrontIndexJob;
use App\Services\Storefront\ModelKitStorefrontIndexImageResolver;
use App\Services\Storefront\ModelKitStorefrontIndexLiquidEncoder;
use App\Services\Storefront\ModelKitStorefrontIndexPokeService;
use App\Services\Storefront\ModelKitStorefrontIndexTagMapper;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

it('maps mk tags into filter attrs the theme JS already reads', function (): void {
    $mapped = (new ModelKitStorefrontIndexTagMapper)->map([
        StorefrontTag::MK_DEPT_MODEL_KITS,
        StorefrontTag::mkGrade('hg'),
        StorefrontTag::mkSubline('uc'),
        StorefrontTag::mkSeries('universal_century'),
        StorefrontTag::MK_LINE_GUNPLA,
    ]);

    expect($mapped)->toBe([
        'grade' => 'hg',
        'sublines' => 'uc',
        'series' => 'universal_century',
        'lines' => 'gunpla',
    ]);
});

it('encodes a compact Shopify theme cache file', function (): void {
    $document = new ModelKitStorefrontIndexDocument(
        generatedAt: '2026-09-14T09:00:00-04:00',
        products: [
            new ModelKitStorefrontIndexRow(
                handle: 'hg-gundam-test-kit',
                title: 'HG Gundam Test Kit',
                image: 'https://cdn.shopify.com/s/files/1/test.jpg',
                priceCents: 4999,
                available: true,
                latestArrival: true,
                arrived: '2026-09-01',
                grade: 'hg',
                sublines: 'uc',
                series: 'universal_century',
                lines: 'gunpla',
            ),
        ],
    );

    $liquid = (new ModelKitStorefrontIndexLiquidEncoder)->encode($document);

    expect($liquid)->toContain('id="ovs-mk-index-cache"')
        ->and($liquid)->toContain('"h":"hg-gundam-test-kit"')
        ->and($liquid)->toContain('"g":"hg"')
        ->and($liquid)->toContain('"c":4999')
        ->and($liquid)->toContain('"pc":0')
        ->and($liquid)->toContain('"op":0');
});

it('reads a Shopify featured image URL from payload_json shapes', function (): void {
    $resolver = new ModelKitStorefrontIndexImageResolver;

    expect($resolver->urlFromPayload([
        'featuredImage' => ['url' => 'https://cdn.shopify.com/s/files/1/a.jpg'],
    ]))->toBe('https://cdn.shopify.com/s/files/1/a.jpg');
});

it('pokes a unique delayed rebuild job so the cache file is recreated', function (): void {
    config(['shopify.mk_storefront_index.enabled' => true]);
    Bus::fake();

    app(ModelKitStorefrontIndexPokeService::class)->poke();

    expect(Cache::get(ModelKitStorefrontIndexPokeService::DIRTY_KEY))->toBeTrue();
    Bus::assertDispatched(RebuildModelKitStorefrontIndexJob::class);
});

it('does not dispatch a rebuild when the cache poke is disabled', function (): void {
    config(['shopify.mk_storefront_index.enabled' => false]);
    Bus::fake();

    app(ModelKitStorefrontIndexPokeService::class)->poke();

    Bus::assertNotDispatched(RebuildModelKitStorefrontIndexJob::class);
});
