<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$query = <<<'GQL'
query ThemeFiles($id: ID!, $filenames: [String!]!) {
  theme(id: $id) {
    files(first: 10, filenames: $filenames) {
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

/** @var App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface::class);
$response = $client->query($query, [
    'id' => 'gid://shopify/OnlineStoreTheme/196218716241',
    'filenames' => [
        'sections/main-collection-product-grid.liquid',
        'snippets/ovs-model-kit-collection-filters.liquid',
        'snippets/ovs-model-kit-filter-handles.liquid',
        'snippets/facets.liquid',
        'assets/ovs-model-kit-collection-filters.js',
    ],
]);

foreach ($response['data']['theme']['files']['nodes'] ?? [] as $node) {
    $content = $node['body']['content'] ?? '';
    $filename = $node['filename'] ?? '';
    $hasMk = str_contains($content, 'ovs-model-kit-collection-filters');
    $hasSd = str_contains($content, 'sd-gundam') || str_contains($content, 'grade-sd-hub');
    echo $filename.PHP_EOL;
    echo '  bytes: '.strlen($content).PHP_EOL;
    echo '  has mk filters: '.($hasMk ? 'yes' : 'no').PHP_EOL;
    echo '  sd-gundam ref: '.($hasSd ? 'yes' : 'no').PHP_EOL;
}
