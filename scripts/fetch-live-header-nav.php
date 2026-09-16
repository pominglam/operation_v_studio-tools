<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$filenames = [
    'snippets/header-mega-menu.liquid',
    'snippets/header-drawer.liquid',
    'snippets/ovs-header-preorders-nav-item.liquid',
];

$result = $client->query(<<<'GQL'
query ThemeFiles($themeId: ID!, $filenames: [String!]) {
  theme(id: $themeId) {
    files(filenames: $filenames, first: 10) {
      nodes { filename body { ... on OnlineStoreThemeFileBodyText { content } } }
    }
  }
}
GQL, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/190542250065',
    'filenames' => $filenames,
]);

foreach (($result['data']['theme']['files']['nodes'] ?? []) as $node) {
    $name = (string) ($node['filename'] ?? '');
    $body = (string) ($node['body']['content'] ?? '');
    $safe = str_replace(['snippets/', '.liquid'], ['', ''], $name);
    file_put_contents('/var/www/html/storage/app/live-'.$safe.'.liquid', $body);
    echo $name.' len='.strlen($body).' preorders='.(str_contains($body, 'ovs-header-preorders-nav-item') ? 'yes' : 'no').PHP_EOL;
}
