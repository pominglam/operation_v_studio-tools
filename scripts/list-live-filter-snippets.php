<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$filenames = [
    'snippets/ovs-pilot-collection-filters.liquid',
    'snippets/ovs-sanding-collection-filters.liquid',
    'snippets/ovs-cutting-collection-filters.liquid',
    'snippets/ovs-paints-collection-filters.liquid',
    'snippets/ovs-markers-collection-filters.liquid',
    'snippets/ovs-panel-liners-collection-filters.liquid',
    'snippets/ovs-brushes-collection-filters.liquid',
    'snippets/ovs-drills-collection-filters.liquid',
    'snippets/ovs-tweezers-collection-filters.liquid',
    'snippets/ovs-scribing-tools-collection-filters.liquid',
    'snippets/ovs-airbrush-collection-filters.liquid',
    'snippets/ovs-workshop-misc-collection-filters.liquid',
    'snippets/ovs-tools-and-supplies-collection-filters.liquid',
    'snippets/ovs-model-kit-collection-filters.liquid',
    'snippets/ovs-store-preorder-collection-filters.liquid',
];

$result = $client->query(<<<'GQL'
query ThemeFiles($themeId: ID!, $filenames: [String!]) {
  theme(id: $themeId) {
    files(filenames: $filenames, first: 20) {
      nodes { filename }
    }
  }
}
GQL, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/190542250065',
    'filenames' => $filenames,
]);

$found = [];
foreach (($result['data']['theme']['files']['nodes'] ?? []) as $node) {
    $found[] = $node['filename'] ?? '';
}
echo "found:\n".implode("\n", $found).PHP_EOL;
echo "missing:\n";
foreach ($filenames as $name) {
    if (! in_array($name, $found, true)) {
        echo $name.PHP_EOL;
    }
}
