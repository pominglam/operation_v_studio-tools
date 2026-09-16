<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$result = $client->query(<<<'GQL'
query ThemeFiles($themeId: ID!, $filenames: [String!]) {
  theme(id: $themeId) {
    files(filenames: $filenames, first: 5) {
      nodes { filename body { ... on OnlineStoreThemeFileBodyText { content } } }
    }
  }
}
GQL, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/190542250065',
    'filenames' => ['snippets/facets.liquid'],
]);
$body = $result['data']['theme']['files']['nodes'][0]['body']['content'] ?? '';
file_put_contents('/var/www/html/storage/app/live-facets-fetched.liquid', $body);
echo 'len='.strlen($body).PHP_EOL;
echo 'workshop: '.(str_contains($body, 'ovs-workshop-misc-collection-filters') ? 'yes' : 'no').PHP_EOL;
echo 'model-kit: '.(str_contains($body, 'ovs-model-kit-collection-filters') ? 'yes' : 'no').PHP_EOL;
echo 'preorder: '.(str_contains($body, 'ovs-store-preorder-collection-filters') ? 'yes' : 'no').PHP_EOL;
