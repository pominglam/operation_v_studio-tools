<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$handles = [
    'latest-arrivals', 'gunpla', 'beginner-kits', 'entry-grade-eg', 'sd-gundam', 'sd-ex-standard', 'sd-cross-silhouette',
    'sd-world-heroes', 'sd-bb-senshi', 'sd-g-generation', 'sd-build-fighters', 'sd-gunpla-kun', 'action-base',
    'high-grade-hg', 'hg-universal-century', 'hg-gundam-seed', 'hg-after-colony', 'hg-iron-blooded-orphans',
    'hg-build-fighters', 'hg-build-divers', 'real-grade-rg', 'master-grade-mg', 'mg-standard', 'mg-ver-ka', 'mgex',
    'master-grade-sd-mgsd', 'perfect-grade-pg', 'gunpla-option-parts', 'gundam-universal-century', 'gundam-alternate-universes',
    'gundam-other-uc-series', 'gundam-other-au-series', 'gundam-mobile-suit-gundam', 'gundam-zeta', 'gundam-zz',
    'gundam-chars-counterattack', 'gundam-0080', 'gundam-0083', 'gundam-seed', 'gundam-wing', 'gundam-00',
    'gundam-iron-blooded-orphans', 'gundam-witch-from-mercury', 'gundam-unicorn', 'g-gundam', 'gundam-build-fighters',
    'gundam-build-divers', 'gundam-age', 'gundam-hathaway', '30-minutes-missions', '30-minutes-sisters', '30-minutes-fantasy',
    '30-minutes-preference', '30-minutes-accessories', 'pokemon', 'kotobukiya', 'moderoid', 'keroro', 'snaa',
    'mechatrowego', 'plamax', 'evangelion', 'other-series', 'doraemon', 'mazinger', 'getter-robo', 'kotetsu-jeeg',
    'patlabor', 'macross-delta', 'armored-trooper-votoms', 'sakura-wars', 'linebarrels-of-iron', 'eureka-seven',
    'one-piece', 'super-robot-wars', 'armored-core',
];

$query = <<<'GQL'
query Collections($query: String!, $after: String) {
  collections(first: 100, query: $query, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes { handle title productsCount { count } }
  }
}
GQL;

/** @var App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface::class);

$found = [];
$cursor = null;
do {
    $response = $client->query($query, ['query' => '', 'after' => $cursor]);
    foreach ($response['data']['collections']['nodes'] ?? [] as $node) {
        $found[$node['handle']] = [
            'title' => $node['title'],
            'count' => $node['productsCount']['count'] ?? null,
        ];
    }
    $pageInfo = $response['data']['collections']['pageInfo'] ?? [];
    $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
} while ($cursor !== null);

$missing = [];
$empty = [];
foreach ($handles as $handle) {
    if (! isset($found[$handle])) {
        $missing[] = $handle;
    } elseif (($found[$handle]['count'] ?? 0) === 0) {
        $empty[] = $handle;
    }
}

echo json_encode([
    'checked' => count($handles),
    'missingInShopify' => $missing,
    'emptyCollections' => $empty,
    'allExist' => $missing === [],
], JSON_PRETTY_PRINT).PHP_EOL;
