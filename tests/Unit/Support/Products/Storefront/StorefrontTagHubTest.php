<?php

declare(strict_types=1);

use App\Support\Products\Storefront\StorefrontDepartment;
use App\Support\Products\Storefront\StorefrontTag;

it('lists hub departments in nav order with unique tags', function (): void {
    $rows = StorefrontTag::toolsAndSuppliesHubDepartments();

    expect($rows)->toHaveCount(13);

    $slugs = array_column($rows, 'slug');
    $tags = array_column($rows, 'tag');

    expect($slugs)->toBe([
        StorefrontDepartment::BRUSHES,
        StorefrontDepartment::DRILLS,
        StorefrontDepartment::TWEEZERS,
        StorefrontDepartment::SCRIBING,
        StorefrontDepartment::ADHESIVES,
        StorefrontDepartment::CUTTING,
        StorefrontDepartment::SANDING,
        StorefrontDepartment::TAPES,
        StorefrontDepartment::MARKERS,
        StorefrontDepartment::PAINTS,
        StorefrontDepartment::DECALS,
        StorefrontDepartment::AIRBRUSH,
        StorefrontDepartment::WORKSHOP_MISC,
    ]);

    expect($tags)->each->toStartWith('ts:dept:');
    expect(array_unique($tags))->toHaveCount(count($tags));
});

it('maps hub department tags from helper', function (): void {
    $tags = StorefrontTag::toolsAndSuppliesHubDepartmentTags();

    expect($tags)->toContain(StorefrontTag::DEPT_BRUSHES)
        ->and($tags)->toContain(StorefrontTag::DEPT_AIRBRUSH)
        ->and($tags)->toHaveCount(13);
});
