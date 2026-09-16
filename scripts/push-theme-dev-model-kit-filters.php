<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('SHOPIFY_THEME_ID') ?: '196218716241';
if ($themeId === '190542250065' && getenv('SHOPIFY_LIVE_THEME_APPROVED') !== '1') {
    fwrite(STDERR, "Refusing live Rise theme push (190542250065). Use AI Dev 196218716241 or set SHOPIFY_LIVE_THEME_APPROVED=1.\n");
    exit(1);
}
$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing — set THEME_ROOT to ovs-shopify-theme\n");
    exit(1);
}

$files = [
    'assets/ovs-model-kit-collection-filters-handles.js',
    'assets/ovs-model-kit-collection-filters.js',
    'assets/ovs-model-kit-collection-filters.css',
    'assets/facets.js',
    'snippets/ovs-model-kit-collection-filters.liquid',
    'snippets/ovs-model-kit-filter-handles.liquid',
    'snippets/ovs-model-kit-filter-data-attrs.liquid',
    'snippets/ovs-model-kit-filter-profile.liquid',
    'snippets/ovs-model-kit-filters-params.liquid',
    'snippets/ovs-model-kit-filters-latest-arrival.liquid',
    'snippets/ovs-model-kit-filters-price.liquid',
    'snippets/ovs-model-kit-filters-grade-gunpla.liquid',
    'snippets/ovs-model-kit-filters-series-uc.liquid',
    'snippets/ovs-model-kit-filters-series-au.liquid',
    'snippets/ovs-model-kit-filters-series-gundam.liquid',
    'snippets/ovs-model-kit-filters-flat-group.liquid',
    'snippets/ovs-gundam-uc-collection-filters.liquid',
    'snippets/ovs-tools-supplies-product-grid-item.liquid',
    'sections/main-collection-product-grid.liquid',
    'sections/ovs-model-kit-index.liquid',
    'sections/ovs-model-kit-card-page.liquid',
    'snippets/facets.liquid',
    'snippets/ovs-model-kits-mega-menu-poc.liquid',
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
