<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$query = <<<'GQL'
query ThemeFiles($themeId: ID!, $filenames: [String!]) {
  theme(id: $themeId) {
    files(filenames: $filenames, first: 5) {
      nodes {
        filename
        body { ... on OnlineStoreThemeFileBodyText { content } }
      }
    }
  }
}
GQL;

$result = $client->query($query, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/190542250065',
    'filenames' => ['sections/main-collection-product-grid.liquid'],
]);
$body = $result['data']['theme']['files']['nodes'][0]['body']['content'] ?? '';
echo 'len='.strlen($body).PHP_EOL;
foreach (['ovs_products_per_page = 250', 'pre-orders', 'ovs-store-preorder-collection-filters', 'small-hide'] as $needle) {
    echo $needle.': '.(str_contains($body, $needle) ? 'yes' : 'NO').PHP_EOL;
}
file_put_contents('/var/www/html/storage/app/live-collection-grid-fetched.liquid', $body);
