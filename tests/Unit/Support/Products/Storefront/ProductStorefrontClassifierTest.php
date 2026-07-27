<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use App\Support\Products\Storefront\StorefrontDepartment;
use Tests\TestCase;

uses(TestCase::class);

function makeClassifierProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies masking tape SKUs with department and width tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MT-10',
        'main_type' => 'supplies',
        'type' => 'Others',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::TAPES)
        ->and($result->legacyTags)->toBe(['supplies', 'Others'])
        ->and($result->storefrontTags)->toBe([
            'ts:dept:tapes',
            'ts:tape:masking',
            'ts:tape:width:10',
        ])
        ->and($result->shopifyTags)->toBe([
            'ts:dept:tapes',
            'ts:tape:masking',
            'ts:tape:width:10',
        ])
        ->and($result->warnings)->toBe([]);
});

it('classifies scribing tape SKUs with department type and width tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-03',
        'description' => 'Scribing Tape 3mm',
        'main_type' => 'supplies',
        'type' => 'Scribing',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::TAPES)
        ->and($result->storefrontTags)->toBe([
            'ts:dept:tapes',
            'ts:tape:scribing',
            'ts:tape:width:3',
        ])
        ->and($result->shopifyTags)->toContain('ts:tape:scribing', 'ts:tape:width:3');
});

it('classifies six millimetre scribing tape width from SKU', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-06',
        'description' => '6MM Scribing Tape',
        'main_type' => 'supplies',
        'type' => 'Scribing',
    ]));

    expect($result->storefrontTags)->toContain('ts:tape:scribing', 'ts:tape:width:6');
});

it('classifies scriber tools as scribing not tapes', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-150',
        'description' => 'Stedi Scriber 1 mm',
        'main_type' => 'tools',
        'type' => 'TOOLS',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::SCRIBING)
        ->and($result->storefrontTags)->toContain('ts:dept:scribing', 'ts:scribing:type:scriber');
});

it('classifies decal softener SKUs', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'ETC-03',
        'main_type' => 'supplies',
        'type' => 'Others',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::DECALS)
        ->and($result->storefrontTags)->toBe([
            'ts:dept:decals',
            'ts:decal:softener',
            'ts:decal:brand:dspiae',
        ])
        ->and($result->shopifyTags)->toContain('ts:dept:decals', 'ts:decal:softener', 'ts:decal:brand:dspiae');
});

it('classifies water decal SKUs with sheet tag and dspiae brand only when titled Dspiae', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'WD-MG-224',
        'main_type' => 'water decals',
        'type' => 'MG',
        'vendor' => 'Dspiae',
        'description' => 'Dspiae water decal - MG Turn A',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::DECALS)
        ->and($result->storefrontTags)->toBe([
            'ts:dept:decals',
            'ts:decal:sheet',
            'ts:decal:brand:dspiae',
        ]);
});

it('classifies generic water decals with unclassified brand tag', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'WD-HGUC-001',
        'main_type' => 'water decals',
        'type' => 'HGUC',
        'description' => 'Water decal - HGUC Penelope',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::DECALS)
        ->and($result->storefrontTags)->toContain('ts:dept:decals', 'ts:decal:sheet', 'ts:decal:brand:unclassified');
});

it('classifies pilot paint SKUs with department and paint tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'XG-001',
        'main_type' => 'supplies',
        'type' => 'PAINT',
        'description' => 'XG-001 Super White DSPIAE 50ml',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::PAINTS)
        ->and($result->storefrontTags)->toContain(
            'ts:dept:paints',
            'ts:paint:product:paint',
            'ts:paint:app:pre-thinned-airbrush',
            'ts:paint:app:airbrush',
            'ts:paint:type:solid',
        );
});

it('classifies paint bundle with bundle product tag', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'OVSP-00001',
        'main_type' => 'supplies',
        'type' => 'BUNDLES',
        'description' => 'DSPIAE Paint Full set',
    ]));

    expect($result->storefrontTags)->toContain('ts:dept:paints', 'ts:paint:product:bundle');
});

it('routes airbrush hardware to airbrush department instead of paints', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'PT-AB',
        'main_type' => 'tools',
        'type' => 'PAINT',
        'description' => 'Wash-Free airbrush',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::AIRBRUSH)
        ->and($result->storefrontTags)->toContain('ts:dept:airbrush');
});

it('classifies dspiae marker with marker department tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MKF-01',
        'main_type' => 'supplies',
        'type' => 'MARKERS',
        'description' => 'DSPiae soft tipped markers fluorescent green',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::MARKERS)
        ->and($result->storefrontTags)->toContain(
            'ts:dept:markers',
            'ts:marker:type:fluorescent',
            'ts:marker:tip:soft',
            'ts:marker:brand:dspiae',
        );
});

it('classifies joint reinforcement as workshop misc not markers', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-58',
        'main_type' => 'supplies',
        'type' => 'MARKERS',
        'description' => 'Joint reinforcement',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::WORKSHOP_MISC)
        ->and($result->storefrontTags)->toContain('ts:dept:workshop-misc');
});

it('classifies stedi mmc hand metallic paints on paints shelf not workshop misc', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MMC-01',
        'main_type' => 'supplies',
        'type' => 'PAINT',
        'description' => 'Zirconium Silver',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::PAINTS)
        ->and($result->storefrontTags)->toContain(
            'ts:dept:paints',
            'ts:paint:product:paint',
            'ts:paint:app:hand',
            'ts:paint:app:airbrush',
            'ts:paint:type:metallic',
        )
        ->and($result->storefrontTags)->not->toContain('ts:dept:workshop-misc');
});

it('classifies panel liner accent and wiper pens on panel liners shelf', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);

    $accent = $classifier->classify(makeClassifierProduct([
        'sku' => 'MP-01',
        'main_type' => 'supplies',
        'type' => 'Others',
        'description' => 'Panel Line Accent Pen (Blue)',
    ]));

    expect($accent->department)->toBe(StorefrontDepartment::PANEL_LINERS)
        ->and($accent->storefrontTags)->toContain(
            'ts:dept:panel-liners',
            'ts:panel-liner:kind:paint',
            'ts:panel-liner:type:normal',
        );

    $wiper = $classifier->classify(makeClassifierProduct([
        'sku' => 'MP-02B',
        'main_type' => 'supplies',
        'type' => 'Others',
        'description' => 'Seepage line wiper pen',
    ]));

    expect($wiper->department)->toBe(StorefrontDepartment::PANEL_LINERS)
        ->and($wiper->storefrontTags)->toContain(
            'ts:dept:panel-liners',
            'ts:panel-liner:kind:tool',
            'ts:panel-liner:type:normal',
        );
});

it('tags fluorescent liquid panel liners for panel liners type filter', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MP-20',
        'main_type' => 'supplies',
        'type' => 'Panel liner',
        'description' => 'Stedi Panel Liner MP-20 Fluorescent Orange',
    ]));

    expect($result->storefrontTags)->toContain(
        'ts:panel-liner:kind:paint',
        'ts:panel-liner:type:fluorescent',
        'ts:paint:type:fluorescent',
    );
});

it('tags normal liquid panel liners for panel liners type filter', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MP-10',
        'main_type' => 'supplies',
        'type' => 'Panel liner',
        'description' => 'Stedi Panel Liner MP-10 Brown',
    ]));

    expect($result->storefrontTags)->toContain(
        'ts:panel-liner:kind:paint',
        'ts:panel-liner:type:normal',
    )->not->toContain('ts:panel-liner:type:fluorescent');
});

it('tags liquid panel liners with paint kind for panel liners page filters', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MP-10',
        'main_type' => 'supplies',
        'type' => 'Panel liner',
        'description' => 'Panel liner brown',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::PAINTS)
        ->and($result->storefrontTags)->toContain(
            'ts:dept:paints',
            'ts:paint:product:panel-line',
            'ts:panel-liner:kind:paint',
            'ts:panel-liner:type:normal',
        );
});

it('classifies airbrush hardware with role tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'PT-AB',
        'main_type' => 'tools',
        'type' => 'PAINT',
        'description' => 'Wash-free airbrush',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::AIRBRUSH)
        ->and($result->storefrontTags)->toContain('ts:dept:airbrush', 'ts:airbrush:role:tool');
});

it('classifies Stedi weathering supplies with weathering department tag', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MP-51',
        'main_type' => 'supplies',
        'type' => 'Weathering',
        'description' => 'Stedi Weathering MP-51 Ground Brown',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::WEATHERING)
        ->and($result->storefrontTags)->toContain('ts:dept:weathering')
        ->and($result->shopifyTags)->toContain('ts:dept:weathering');
});

it('classifies brushes department with brush type tag', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-130',
        'main_type' => 'tools',
        'type' => 'BRUSHES',
        'description' => 'Fine brush size 00',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::BRUSHES)
        ->and($result->storefrontTags)->toContain('ts:dept:brushes', 'ts:brush:type:hand');
});

it('classifies tweezers department with line and style tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-161',
        'main_type' => 'tools',
        'type' => 'Tweezers',
        'description' => 'Stedi Thick-Wall Tweezers (Straight)',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::TWEEZERS)
        ->and($result->storefrontTags)->toContain(
            'ts:dept:tweezers',
            'ts:tweezer:line:thick-wall',
            'ts:tweezer:style:straight',
        );
});

it('exports no shopify tags when main_type is empty even for pilot SKUs', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MT-05',
        'main_type' => '',
        'type' => 'Others',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::TAPES)
        ->and($result->legacyTags)->toBe([])
        ->and($result->storefrontTags)->not->toBeEmpty()
        ->and($result->shopifyTags)->toBe([
            'ts:dept:tapes',
            'ts:tape:masking',
            'ts:tape:width:5',
        ])
        ->and($result->warnings)->toContain('empty_main_type');
});

it('includes latest arrival in merged shopify tags for pilot products', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MT-02',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => true,
    ]));

    expect($result->shopifyTags)->toBe([
        'ts:dept:tapes',
        'ts:tape:masking',
        'ts:tape:width:2',
        'latest arrival',
    ]);
});

it('skips storefront tags when department is not enabled', function (): void {
    config()->set('storefront_classification.enabled_departments', []);

    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MT-10',
        'main_type' => 'supplies',
        'type' => 'Others',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::TAPES)
        ->and($result->storefrontTags)->toBe([])
        ->and($result->shopifyTags)->toBe([])
        ->and($result->warnings)->toContain('department_not_enabled');
});

it('deduplicates tags case-insensitively', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'ETC-04',
        'main_type' => 'Supplies',
        'type' => 'supplies',
    ]));

    $uniqueCount = count(array_unique(array_map('strtolower', $result->shopifyTags)));
    expect($result->shopifyTags)->toHaveCount($uniqueCount);
});

it('classifies adhesive sandpaper as sheet with grit tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-B600',
        'description' => 'Adhesive Sandpaper 20mm x 80mm 600 grit',
        'main_type' => 'supplies',
        'type' => 'SANDING',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::SANDING)
        ->and($result->storefrontTags)->toBe([
            'ts:dept:sanding',
            'ts:sand:type:sheet',
            'ts:sand:grit:medium',
        ]);
});

it('classifies carbon fiber sanding board as board-plate without grit tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-D20',
        'description' => 'Carbon Fiber Sanding Board 20mm',
        'main_type' => 'tools',
        'type' => 'SANDING',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::SANDING)
        ->and($result->storefrontTags)->toBe([
            'ts:dept:sanding',
            'ts:sand:type:board-plate',
        ]);
});

it('classifies MS-B400 misfiled row as sanding sheet', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-B400',
        'description' => 'Adhesive Sandpaper 20mm x 80mm 400 grit',
        'main_type' => 'supplies',
        'type' => 'TOOLS',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::SANDING)
        ->and($result->storefrontTags)->toContain('ts:sand:type:sheet', 'ts:sand:grit:coarse');
});

it('classifies glass polishing file as glass-file', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-41',
        'description' => 'Stedi Ultra Fine Point Polishing File 10000 grit',
        'main_type' => 'tools',
        'type' => 'SANDING',
    ]));

    expect($result->storefrontTags)->toContain('ts:sand:type:glass-file', 'ts:sand:grit:polish');
});

it('classifies beginner nipper with cutting tags', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'MS-104',
        'description' => "Beginner's single blade nipper",
        'main_type' => 'tools',
        'type' => 'NIPPER',
    ]));

    expect($result->department)->toBe(StorefrontDepartment::CUTTING)
        ->and($result->storefrontTags)->toContain(
            'ts:dept:cutting',
            'ts:cut:nipper',
            'ts:cut:nipper-beginner',
            'ts:cut:style:beginner',
            'ts:cut:style:single-edge',
        );
});

it('classifies OLFA knife as cutting knife utility style', function (): void {
    $classifier = app(ProductStorefrontClassifier::class);
    $result = $classifier->classify(makeClassifierProduct([
        'sku' => 'AK-1/5B',
        'description' => 'OLFA Knife',
        'main_type' => 'tools',
        'type' => 'TOOLS',
    ]));

    expect($result->storefrontTags)->toContain('ts:cut:knife', 'ts:cut:style:utility-olfa');
});
