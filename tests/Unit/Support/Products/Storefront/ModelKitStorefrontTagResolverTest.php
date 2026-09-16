<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\ModelKitAccessoryKind;
use App\Support\Products\Storefront\ModelKitStorefrontTagResolver;
use App\Support\Products\Storefront\StorefrontTag;

it('tags gunpla action bases with dept, gunpla line, and action_base', function (): void {
    $resolver = new ModelKitStorefrontTagResolver;

    $product = new Product([
        'main_type' => 'model kit',
        'sku' => '5059255',
        'type' => 'ACTION BASE',
        'product_line' => 'Action Base',
        'accessory_kind' => ModelKitAccessoryKind::DISPLAY_STAND,
        'description' => 'Action Base 1/100 Gray',
    ]);

    expect($resolver->tagsForProduct($product))->toBe([
        StorefrontTag::MK_DEPT_MODEL_KITS,
        StorefrontTag::MK_LINE_GUNPLA,
        StorefrontTag::MK_LINE_ACTION_BASE,
    ]);
});

it('tags builders parts system base for gunpla as action_base', function (): void {
    $resolver = new ModelKitStorefrontTagResolver;

    $product = new Product([
        'main_type' => 'model kit',
        'sku' => '5059030',
        'type' => 'SYSTEM BASE',
        'product_line' => 'Action Base',
        'accessory_kind' => ModelKitAccessoryKind::DISPLAY_STAND,
        'description' => 'System Base 001',
    ]);

    expect($resolver->tagsForProduct($product))->toContain(StorefrontTag::MK_LINE_ACTION_BASE)
        ->and($resolver->tagsForProduct($product))->toContain(StorefrontTag::MK_DEPT_MODEL_KITS);
});

it('tags pokemon plamo with mk grade pokemon not entry grade', function (): void {
    $resolver = new ModelKitStorefrontTagResolver;

    $product = new Product([
        'main_type' => 'model kit',
        'sku' => '5084707',
        'product_line' => 'Pokémon Plamo Collection',
        'franchise' => 'Pokémon',
        'description' => 'Pokémon PLAMO COLLECTION QUICK!! 30 Dragonair',
    ]);

    expect($resolver->tagsForProduct($product))->toContain(StorefrontTag::mkGrade('pokemon'))
        ->and($resolver->tagsForProduct($product))->not->toContain(StorefrontTag::mkGrade('eg'));
});

it('does not tag 30mm system base white as gunpla action_base', function (): void {
    $resolver = new ModelKitStorefrontTagResolver;

    $product = new Product([
        'main_type' => 'model kit',
        'sku' => '5058285',
        'type' => 'SYSTEM BASE',
        'product_line' => '30 Minutes Missions',
        'accessory_kind' => ModelKitAccessoryKind::DISPLAY_STAND,
        'description' => 'Builders Parts - System Base 001 (White)',
    ]);

    expect($resolver->tagsForProduct($product))->not->toContain(StorefrontTag::MK_LINE_ACTION_BASE);
});

it('tags PLAMAX Asuka as Evangelion and PLAMAX, not Votoms', function (): void {
    $resolver = new ModelKitStorefrontTagResolver;

    $product = new Product([
        'main_type' => 'model kit',
        'sku' => 'GSC-M01345',
        'description' => 'PLAMAX Asuka Shikinami Langley',
        'type' => 'PLAMAX',
        'series' => 'Evangelion',
        'franchise' => 'Evangelion',
    ]);

    $tags = $resolver->tagsForProduct($product);

    expect($tags)->toContain(StorefrontTag::MK_LINE_EVANGELION)
        ->and($tags)->toContain(StorefrontTag::MK_LINE_PLAMAX)
        ->and($tags)->toContain(StorefrontTag::mkSeries('evangelion'))
        ->and($tags)->not->toContain(StorefrontTag::mkSeries('armored_trooper_votoms'));
});

it('tags Asuka as Evangelion from the character name when franchise is empty', function (): void {
    $resolver = new ModelKitStorefrontTagResolver;

    $product = new Product([
        'main_type' => 'model kit',
        'sku' => 'GSC-M01345',
        'description' => 'PLAMAX Asuka Shikinami Langley',
        'type' => 'PLAMAX',
    ]);

    expect($resolver->tagsForProduct($product))->toContain(StorefrontTag::MK_LINE_EVANGELION);
});
