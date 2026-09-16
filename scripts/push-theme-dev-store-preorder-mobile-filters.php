<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('THEME_ID') ?: '196218716241';
$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing — set THEME_ROOT to ovs-shopify-theme\n");
    exit(1);
}

$files = [
    'assets/global.js',
    'assets/facets.js',
    'assets/ovs-store-preorder-collection-filters.css',
    'snippets/facets.liquid',
    'snippets/ovs-store-preorder-collection-filters.liquid',
    'snippets/ovs-store-preorder-close-status-group.liquid',
    'snippets/ovs-store-preorder-series-filters.liquid',
    'snippets/ovs-model-kit-filters-flat-group.liquid',
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
