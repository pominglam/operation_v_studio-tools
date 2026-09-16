<?php

declare(strict_types=1);

/**
 * Apply operator-approved taxonomy fixes (2026-09-10).
 * Run: docker exec pricing-tool-php php scripts/apply-taxonomy-approved.php [--dry-run]
 */

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$repo = app(ProductRepository::class);
$shopifyPush = app(ShopifyProductPushBySkusService::class);

/** @var list<array{sku: string, patch: array<string, mixed>, note: string}> */
$patches = [
    [
        'sku' => '5059578',
        'patch' => ['product_line' => 'Action Base'],
        'note' => 'Action base shelf — product_line Action Base (operator approved)',
    ],
    [
        'sku' => '5061552',
        'patch' => ['department' => 'accessories'],
        'note' => '30MM option parts — department accessories (operator approved)',
    ],
    [
        'sku' => '5061790',
        'patch' => ['department' => 'accessories'],
        'note' => '30MM option parts — department accessories (operator approved)',
    ],
];

/** @var list<string> */
$shopifyPushSkus = [];

foreach ($patches as $entry) {
    $product = Product::query()->where('sku', $entry['sku'])->first();
    if ($product === null) {
        echo "MISSING SKU: {$entry['sku']}\n";

        continue;
    }

    echo "{$entry['sku']}: {$entry['note']}\n";
    foreach ($entry['patch'] as $field => $value) {
        $before = $product->getAttribute($field);
        echo "  {$field}: ".($before ?? 'null')." -> {$value}\n";
    }

    if ($dryRun) {
        continue;
    }

    DB::transaction(function () use ($product, $entry, $repo): void {
        $product->fill($entry['patch']);
        $repo->save($product);
    });
    $shopifyPushSkus[] = $entry['sku'];
}

// DMM-* metallic markers → Tools & Supplies (supplies department)
$dmmProducts = Product::query()
    ->where('sku', 'like', 'DMM-%')
    ->whereNull('archived_at')
    ->orderBy('sku')
    ->get();

echo "\nDMM markers ({$dmmProducts->count()}):\n";
foreach ($dmmProducts as $product) {
    $patch = [
        'department' => 'supplies',
        'main_type' => 'tools',
        'product_line' => 'Stedi Markers',
        'manufacturer' => 'Stedi',
        'workshop_shelf' => 'Markers',
        'grade' => null,
        'subline' => null,
        'series' => null,
        'franchise' => null,
    ];
    if ((string) $product->type !== 'MARKERS') {
        $patch['type'] = 'MARKERS';
    }

    echo "{$product->sku}: model kits scope -> T&S supplies\n";
    if ($dryRun) {
        continue;
    }

    DB::transaction(function () use ($product, $patch, $repo): void {
        $product->fill($patch);
        $repo->save($product);
    });
    $shopifyPushSkus[] = (string) $product->sku;
}

// Bandai Spirits manufacturer normalization (cosmetic casing)
$bandaiCount = Product::query()
    ->whereNull('archived_at')
    ->whereIn('manufacturer', ['BANDAI HOBBY', 'BANDAI', 'Bandai'])
    ->count();

echo "\nBandai Spirits manufacturer: {$bandaiCount} products\n";
if (! $dryRun && $bandaiCount > 0) {
    $updated = Product::query()
        ->whereNull('archived_at')
        ->whereIn('manufacturer', ['BANDAI HOBBY', 'BANDAI', 'Bandai'])
        ->update(['manufacturer' => 'Bandai Spirits']);
    echo "  updated: {$updated}\n";
}

$shopifyPushSkus = array_values(array_unique($shopifyPushSkus));
echo "\nShopify push SKUs: ".count($shopifyPushSkus)."\n";

if ($dryRun) {
    echo "Dry run — no DB or Shopify writes.\n";
    exit(0);
}

if ($shopifyPushSkus === []) {
    exit(0);
}

$rows = $shopifyPush->push($shopifyPushSkus, new \App\DTOs\Shopify\ShopifyProductPushOptionsDTO(
    info: true,
    images: false,
    quantities: false,
    price: false,
    publishStatus: false,
    salesChannels: false,
));
$succeeded = count(array_filter($rows, static fn (array $row): bool => $row['action'] !== 'error'));
$failed = count($rows) - $succeeded;
echo "Shopify succeeded: {$succeeded}\n";
echo "Shopify failed: {$failed}\n";
foreach ($rows as $row) {
    if ($row['action'] === 'error') {
        echo "  FAIL {$row['sku']}: {$row['tags']}\n";
    }
}
