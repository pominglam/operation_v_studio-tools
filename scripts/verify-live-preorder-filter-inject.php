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

$response = $client->query($query, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/190542250065',
    'filenames' => [
        'sections/main-collection-product-grid.liquid',
        'snippets/facets.liquid',
    ],
]);

foreach (($response['data']['theme']['files']['nodes'] ?? []) as $node) {
    $content = (string) ($node['body']['content'] ?? '');
    echo $node['filename'].' len='.strlen($content).PHP_EOL;
    echo '  js: '.(str_contains($content, 'ovs-store-preorder-collection-filters.js') ? 'yes' : 'no').PHP_EOL;
    echo '  snippet: '.(str_contains($content, 'ovs-store-preorder-collection-filters') ? 'yes' : 'no').PHP_EOL;
    echo '  flag: '.(str_contains($content, 'ovs_is_preorder_catalog') ? 'yes' : 'no').PHP_EOL;
}
