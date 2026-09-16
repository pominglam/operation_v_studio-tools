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
    files(filenames: $filenames, first: 2) {
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
    'filenames' => ['snippets/facets.liquid'],
]);
$content = (string) ($response['data']['theme']['files']['nodes'][0]['body']['content'] ?? '');
file_put_contents('/var/www/html/storage/app/live-facets.liquid', $content);
echo 'wrote '.strlen($content)." bytes\n";
echo (str_contains($content, 'ovs_is_preorder_catalog') ? 'has flag' : 'no flag')."\n";
echo (str_contains($content, 'if ovs_is_preorder_catalog') ? 'has skip' : 'no skip')."\n";
