<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;

$client = app(ShopifyAdminGraphQlClientInterface::class);
$skus = ['MS-11', 'MS-12', 'MS-14', 'MS-15', 'MS-16', 'MS-17', 'MS-160', 'MS-161', 'MS-162', 'MS-163'];

$variants = DB::table('shopify_product_variants as spv')
    ->join('shopify_products as sp', 'sp.gid', '=', 'spv.product_gid')
    ->whereIn('spv.sku', $skus)
    ->where('sp.status', 'ACTIVE')
    ->select(['spv.sku', 'sp.gid', 'sp.handle', 'sp.title'])
    ->orderBy('spv.sku')
    ->get();

foreach ($variants as $row) {
    $response = $client->query(ShopifyAdminGraphQlQueries::PRODUCT_TAGS_BY_ID, [
        'id' => (string) $row->gid,
    ]);
    $node = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : null;
    $tags = is_array($node['tags'] ?? null) ? $node['tags'] : [];
    $styleTags = array_values(array_filter($tags, static fn (string $t): bool => str_starts_with($t, 'ts:tweezer:style:')));
    echo $row->sku.' | '.$row->handle.' | style_tags='.implode(', ', $styleTags).' | all='.implode(', ', $tags)."\n";
}
