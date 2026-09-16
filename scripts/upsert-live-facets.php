<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$body = file_get_contents('/var/www/html/storage/app/live-facets.liquid');
if (! is_string($body) || $body === '') {
    fwrite(STDERR, "live-facets.liquid missing\n");
    exit(1);
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
    'files' => [[
        'filename' => 'snippets/facets.liquid',
        'body' => ['type' => 'TEXT', 'value' => $body],
    ]],
]);
echo json_encode($result['data']['themeFilesUpsert'] ?? $result, JSON_PRETTY_PRINT).PHP_EOL;
$errors = $result['data']['themeFilesUpsert']['userErrors'] ?? [];
exit($errors !== [] ? 1 : 0);
