<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing — set THEME_ROOT\n");
    exit(1);
}

$themeIds = array_values(array_filter(array_map(
    static fn (string $id): string => trim($id),
    explode(',', (string) (getenv('SHOPIFY_THEME_IDS') ?: '196218716241,190542250065')),
)));

if (in_array('190542250065', $themeIds, true) && getenv('SHOPIFY_LIVE_THEME_APPROVED') !== '1') {
    fwrite(STDERR, "Refusing live Rise theme push. Set SHOPIFY_LIVE_THEME_APPROVED=1.\n");
    exit(1);
}

$files = [
    'assets/ovs-model-kit-collection-filters.js',
    'sections/main-collection-product-grid.liquid',
    'snippets/ovs-model-kit-filters-grade-gunpla.liquid',
    'snippets/ovs-store-preorder-attrs.liquid',
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

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);

foreach ($themeIds as $themeId) {
    $gid = str_starts_with($themeId, 'gid://')
        ? $themeId
        : 'gid://shopify/OnlineStoreTheme/'.$themeId;
    $response = $client->query(ShopifyAdminGraphQlMutations::THEME_FILES_UPSERT, [
        'themeId' => $gid,
        'files' => $inputs,
    ]);
    $errors = $response['data']['themeFilesUpsert']['userErrors'] ?? ($response['errors'] ?? []);
    if ($errors !== []) {
        fwrite(STDERR, "theme {$themeId} failed: ".json_encode($errors).PHP_EOL);
        exit(1);
    }
    $upserted = $response['data']['themeFilesUpsert']['upsertedThemeFiles'] ?? [];
    fwrite(STDOUT, "theme {$themeId}: ".count($upserted)." files\n");
}
