<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$query = <<<'GQL'
query ThemeFiles($id: ID!, $filenames: [String!]!) {
  theme(id: $id) {
    files(first: 20, filenames: $filenames) {
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

$filenames = [
    'templates/collection.json',
    'sections/main-collection-product-grid.liquid',
    'snippets/ovs-model-kit-filter-profile.liquid',
    'snippets/ovs-model-kit-filters-flat-group.liquid',
    'snippets/ovs-model-kit-filters-params.liquid',
];

/** @var App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface::class);
$response = $client->query($query, [
    'id' => 'gid://shopify/OnlineStoreTheme/196218716241',
    'filenames' => $filenames,
]);

foreach ($response['data']['theme']['files']['nodes'] ?? [] as $node) {
    $content = $node['body']['content'] ?? '';
    $filename = $node['filename'] ?? '';
    echo '=== '.$filename.' ('.strlen($content).' bytes) ==='.PHP_EOL;
    if ($filename === 'templates/collection.json') {
        echo $content.PHP_EOL;

        continue;
    }
    if (str_contains($filename, 'main-collection-product-grid')) {
        foreach (explode("\n", $content) as $i => $line) {
            if (str_contains($line, 'ovs-model-kit') || str_contains($line, 'enable_filtering')) {
                echo ($i + 1).': '.$line.PHP_EOL;
            }
        }

        continue;
    }
    if (str_contains($filename, 'filter-profile')) {
        echo (str_contains($content, 'sd-gundam') ? 'has sd-gundam' : 'NO sd-gundam').PHP_EOL;
        echo (str_contains($content, 'grade-sd-hub') ? 'has grade-sd-hub' : 'NO grade-sd-hub').PHP_EOL;
    }
}
