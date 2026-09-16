<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = '196218716241';
$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing\n");
    exit(1);
}

$files = [
    'assets/global.js',
    'assets/ovs-mobile-nav-drawer.js',
    'assets/ovs-mobile-nav-drawer.css',
    'assets/ovs-model-kits-mega-menu-poc.js',
    'assets/ovs-model-kits-mega-menu-poc.css',
    'assets/ovs-model-kit-collection-filters-handles.js',
    'assets/ovs-model-kit-collection-filters.js',
    'assets/ovs-model-kit-collection-filters.css',
    'assets/ovs-store-preorder-collection-filters.js',
    'assets/ovs-store-preorder-collection-filters.css',
    'assets/ovs-collection-arrival-sort.js',
    'assets/facets.js',
    'sections/header.liquid',
    'sections/main-collection-banner.liquid',
    'sections/main-collection-product-grid.liquid',
    'snippets/header-mega-menu.liquid',
    'snippets/header-dropdown-menu.liquid',
    'snippets/header-drawer.liquid',
    'snippets/ovs-header-preorders-nav-item.liquid',
    'snippets/ovs-model-kit-collection-nav.liquid',
    'snippets/ovs-preorder-collection-nav.liquid',
    'snippets/ovs-store-preorder-collection-filters.liquid',
    'snippets/ovs-store-preorder-attrs.liquid',
    'snippets/ovs-model-kits-mega-menu-poc.liquid',
    'snippets/ovs-tools-supplies-mega-menu-poc.liquid',
    'snippets/ovs-miscellaneous-mega-menu-poc.liquid',
    'snippets/ovs-model-kit-collection-filters.liquid',
    'snippets/ovs-model-kit-filter-handles.liquid',
    'snippets/ovs-model-kit-filter-profile.liquid',
    'snippets/ovs-model-kit-filters-params.liquid',
    'snippets/ovs-model-kit-filters-price.liquid',
    'snippets/ovs-model-kit-filters-grade-gunpla.liquid',
    'snippets/ovs-model-kit-filters-series-uc.liquid',
    'snippets/ovs-model-kit-filters-series-au.liquid',
    'snippets/ovs-model-kit-filters-series-gundam.liquid',
    'snippets/ovs-model-kit-filters-flat-group.liquid',
    'snippets/ovs-gundam-uc-collection-filters.liquid',
    'snippets/ovs-tools-supplies-product-grid-item.liquid',
    'snippets/ovs-model-kit-filter-data-attrs.liquid',
    'sections/ovs-model-kit-index.liquid',
    'sections/ovs-model-kit-card-page.liquid',
    'snippets/ovs-collection-arrival-date.liquid',
    'snippets/facets.liquid',
];

$inputs = [];
foreach ($files as $key) {
    $path = $themeRoot.'/'.$key;
    if (! is_readable($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }
    $inputs[] = [
        'filename' => $key,
        'body' => ['type' => 'TEXT', 'value' => file_get_contents($path)],
    ];
}

$mutation = <<<'GQL'
mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
  themeFilesUpsert(themeId: $themeId, files: $files) {
    upsertedThemeFiles { filename }
    userErrors { field message }
  }
}
GQL;

/** @var ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$response = $client->query($mutation, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/'.$themeId,
    'files' => $inputs,
]);

echo json_encode($response, JSON_PRETTY_PRINT).PHP_EOL;

$errors = $response['data']['themeFilesUpsert']['userErrors'] ?? [];
exit($errors !== [] ? 1 : 0);
