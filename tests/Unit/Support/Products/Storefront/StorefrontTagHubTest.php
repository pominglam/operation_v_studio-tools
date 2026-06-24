<?php

declare(strict_types=1);

use App\Support\Products\Storefront\StorefrontDepartment;
use App\Support\Products\Storefront\StorefrontTag;

it('lists hub departments A–Z with Other last', function (): void {
    $rows = StorefrontTag::toolsAndSuppliesHubDepartments();

    expect($rows)->toHaveCount(14);

    $slugs = array_column($rows, 'slug');
    $tags = array_column($rows, 'tag');
    $labels = array_column($rows, 'label');

    expect($slugs)->toBe([
        StorefrontDepartment::ADHESIVES,
        StorefrontDepartment::AIRBRUSH,
        StorefrontDepartment::BRUSHES,
        StorefrontDepartment::DECALS,
        StorefrontDepartment::DRILLS,
        StorefrontDepartment::MARKERS,
        StorefrontDepartment::CUTTING,
        StorefrontDepartment::PANEL_LINERS,
        StorefrontDepartment::PAINTS,
        StorefrontDepartment::SANDING,
        StorefrontDepartment::SCRIBING,
        StorefrontDepartment::TAPES,
        StorefrontDepartment::TWEEZERS,
        StorefrontDepartment::WORKSHOP_MISC,
    ]);

    expect($labels)->toBe([
        'Adhesives',
        'Airbrush',
        'Brushes',
        'Decals',
        'Drills & bits',
        'Markers',
        'Nippers & knives',
        'Panel liners',
        'Paints',
        'Sanding',
        'Scribing tools',
        'Tapes',
        'Tweezers',
        'Other',
    ]);

    expect($tags)->each->toStartWith('ts:dept:');
    expect(array_unique($tags))->toHaveCount(count($tags));
});

it('lists nav menu children A–Z with All and Other footer rows', function (): void {
    $rows = StorefrontTag::toolsAndSuppliesNavMenuChildren();

    expect($rows)->toHaveCount(15);
    expect($rows[0]['handle'])->toBe('adhesives');
    expect($rows[12]['handle'])->toBe('tweezers');
    expect($rows[13])->toMatchArray(['handle' => 'tools-and-supplies', 'title' => 'All tools & supplies', 'footer' => true]);
    expect($rows[14])->toMatchArray(['handle' => 'workshop-misc', 'title' => 'Other', 'footer' => true]);
});

it('maps hub department tags from helper', function (): void {
    $tags = StorefrontTag::toolsAndSuppliesHubDepartmentTags();

    expect($tags)->toContain(StorefrontTag::DEPT_BRUSHES)
        ->and($tags)->toContain(StorefrontTag::DEPT_AIRBRUSH)
        ->and($tags)->toHaveCount(14);
});
