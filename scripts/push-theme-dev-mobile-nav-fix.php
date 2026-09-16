<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = '196218716241';
$themeRoot = getenv('THEME_ROOT') ?: __DIR__.'/theme-push-staging';
if (! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: staging dir missing at {$themeRoot}\n");
    exit(1);
}

$files = [
    'assets/global.js',
    'assets/ovs-mobile-nav-drawer.js',
    'assets/ovs-mobile-nav-drawer.css',
    'sections/header.liquid',
    'snippets/ovs-model-kits-mega-menu-poc.liquid',
    'snippets/ovs-tools-supplies-mega-menu-poc.liquid',
    'snippets/ovs-miscellaneous-mega-menu-poc.liquid',
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
