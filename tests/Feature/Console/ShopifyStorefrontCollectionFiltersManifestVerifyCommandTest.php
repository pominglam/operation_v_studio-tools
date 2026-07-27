<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('passes when manifest covers all ovs collection filter snippets', function (): void {
    $themeRoot = realpath(base_path('../ovs-shopify-theme'));
    if ($themeRoot === false) {
        test()->markTestSkipped('ovs-shopify-theme sibling checkout not available (set OVS_SHOPIFY_THEME_PATH).');
    }

    Artisan::call('shopify:storefront-collection-filters-manifest-verify', [
        '--theme-path' => $themeRoot,
    ]);

    expect(Artisan::output())->toContain('Manifest OK');
})->group('storefront', 'manifest');

it('fails when a filter snippet handle is missing from the manifest', function (): void {
    $themeRoot = realpath(base_path('../ovs-shopify-theme'));
    if ($themeRoot === false) {
        test()->markTestSkipped('ovs-shopify-theme sibling checkout not available (set OVS_SHOPIFY_THEME_PATH).');
    }

    $manifestPath = $themeRoot.'/docs/storefront-ts-collection-filters.manifest.json';
    $original = file_get_contents($manifestPath);
    expect($original)->not->toBeFalse();

    $manifest = json_decode((string) $original, true, 512, JSON_THROW_ON_ERROR);
    array_pop($manifest['collectionsWithCheckboxFilters']);
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    try {
        $exitCode = Artisan::call('shopify:storefront-collection-filters-manifest-verify', [
            '--theme-path' => $themeRoot,
        ]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('out of sync');
    } finally {
        file_put_contents($manifestPath, $original);
    }
})->group('storefront', 'manifest');

it('validates a minimal fixture theme without the sibling checkout', function (): void {
    $fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ovs-filter-manifest-'.uniqid('', true);
    $snippetsDir = $fixtureRoot.DIRECTORY_SEPARATOR.'snippets';
    $docsDir = $fixtureRoot.DIRECTORY_SEPARATOR.'docs';
    mkdir($snippetsDir, 0777, true);
    mkdir($docsDir, 0777, true);

    file_put_contents(
        $snippetsDir.DIRECTORY_SEPARATOR.'ovs-pilot-collection-filters.liquid',
        "{%- if collection.handle == 'tapes' -%}\n<input data-ovs-width=\"10\">\n{%- endif -%}\n",
    );

    file_put_contents(
        $docsDir.DIRECTORY_SEPARATOR.'storefront-ts-collection-filters.manifest.json',
        json_encode([
            'version' => 1,
            'collectionsWithCheckboxFilters' => [],
            'collectionsWithoutCheckboxFilters' => [],
        ], JSON_PRETTY_PRINT),
    );

    try {
        $exitCode = Artisan::call('shopify:storefront-collection-filters-manifest-verify', [
            '--theme-path' => $fixtureRoot,
        ]);

        expect($exitCode)->toBe(1);
        expect(Artisan::output())->toContain('out of sync');
    } finally {
        unlink($snippetsDir.DIRECTORY_SEPARATOR.'ovs-pilot-collection-filters.liquid');
        rmdir($snippetsDir);
        unlink($docsDir.DIRECTORY_SEPARATOR.'storefront-ts-collection-filters.manifest.json');
        rmdir($docsDir);
        rmdir($fixtureRoot);
    }
})->group('storefront', 'manifest');
