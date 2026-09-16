<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('SHOPIFY_THEME_ID') ?: '190542250065';
$filename = getenv('THEME_FILE') ?: 'templates/collection.json';

$query = <<<'GQL'
query themeFile($themeId: ID!, $filenames: [String!]!) {
  theme(id: $themeId) {
    files(filenames: $filenames) {
      nodes {
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

echo $response['data']['theme']['files']['nodes'][0]['body']['content'] ?? '';
