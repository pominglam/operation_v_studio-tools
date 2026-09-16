<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('SHOPIFY_THEME_ID') ?: '196218716241';
if ($themeId === '190542250065' && getenv('SHOPIFY_LIVE_THEME_APPROVED') !== '1') {
    fwrite(STDERR, "Refusing live Rise theme push (190542250065). Set SHOPIFY_LIVE_THEME_APPROVED=1.\n");
    exit(1);
}

$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing\n");
    exit(1);
}

$files = [
    'assets/ovs-store-preorder-isolate.js',
    'assets/ovs-store-preorder-isolate.css',
    'layout/theme.liquid',
    'sections/main-product.liquid',
    'sections/featured-product.liquid',
    'snippets/buy-buttons.liquid',
    'snippets/card-product.liquid',
    'snippets/ovs-store-preorder-attrs.liquid',
    'snippets/ovs-store-preorder-closed.liquid',
    'snippets/ovs-store-preorder-line-properties.liquid',
    'snippets/ovs-store-preorder-pricing.liquid',
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
