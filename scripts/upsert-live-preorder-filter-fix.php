<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$files = [
    'snippets/header-dropdown-menu.liquid' => '/var/www/html/storage/app/live-header-dropdown-menu.liquid',
];

$inputs = [];
foreach ($files as $filename => $path) {
    $body = file_get_contents($path);
    if (! is_string($body) || $body === '') {
        fwrite(STDERR, "Missing {$path}\n");
        exit(1);
    }
    $inputs[] = ['filename' => $filename, 'body' => ['type' => 'TEXT', 'value' => $body]];
}

$mutation = <<<'GQL'
mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
  themeFilesUpsert(themeId: $themeId, files: $files) {
    upsertedThemeFiles { filename }
    userErrors { field message }
  }
}
GQL;

$result = $client->query($mutation, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/190542250065',
    'files' => $inputs,
]);
echo json_encode($result['data']['themeFilesUpsert'] ?? $result, JSON_PRETTY_PRINT).PHP_EOL;
exit((($result['data']['themeFilesUpsert']['userErrors'] ?? []) !== []) ? 1 : 0);
