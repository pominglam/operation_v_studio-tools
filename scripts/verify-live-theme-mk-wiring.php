<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('SHOPIFY_THEME_ID') ?: '190542250065';
$filename = getenv('THEME_FILE') ?: 'sections/main-collection-product-grid.liquid';

$query = <<<'GQL'
query themeFile($themeId: ID!, $filenames: [String!]!) {
  theme(id: $themeId) {
    files(filenames: $filenames) {
      nodes {
        filename
        body {
          ... on OnlineStoreThemeFileBodyText {
            content
          }
        }
      }
    }
  }
}
GQL;

/** @var ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$response = $client->query($query, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/'.$themeId,
    'filenames' => [$filename],
]);

$content = $response['data']['theme']['files']['nodes'][0]['body']['content'] ?? '';

$checks = [
    'model-kits in handle csv' => str_contains($content, 'model-kits'),
    'filters.js script tag' => str_contains($content, 'ovs-model-kit-collection-filters.js'),
    'latest-arrival render' => str_contains($content, 'ovs-model-kit-filters-latest-arrival'),
    'collection-filters render' => str_contains($content, 'ovs-model-kit-collection-filters'),
];

foreach ($checks as $label => $pass) {
    echo ($pass ? 'PASS' : 'FAIL').": {$label}\n";
}
