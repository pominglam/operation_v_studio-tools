<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;
use App\Models\Product;
use App\Services\Plamod\PlamodPreorderSettingsService;

$settings = app(PlamodPreorderSettingsService::class)->get();
$excluded = $settings['excluded_categories'];

echo "=== EXCLUDED CATEGORIES (settings) ===\n";
echo json_encode($excluded, JSON_PRETTY_PRINT)."\n\n";

$active = PlamodPreorder::query()->active();
$allActive = (clone $active)->count();

echo "=== ACTIVE PREORDERS: {$allActive} total ===\n\n";

echo "--- By series (active, top 40) ---\n";
$bySeries = (clone $active)
    ->selectRaw('COALESCE(NULLIF(TRIM(series), ""), "(blank)") as series_name, COUNT(*) as cnt')
    ->groupBy('series_name')
    ->orderByDesc('cnt')
    ->limit(40)
    ->get();
foreach ($bySeries as $row) {
    echo sprintf("%4d  %s\n", $row->cnt, $row->series_name);
}

echo "\n--- By category (active, top 30) ---\n";
$byCategory = (clone $active)
    ->selectRaw('COALESCE(NULLIF(TRIM(category), ""), "(blank)") as category_name, COUNT(*) as cnt')
    ->groupBy('category_name')
    ->orderByDesc('cnt')
    ->limit(30)
    ->get();
foreach ($byCategory as $row) {
    echo sprintf("%4d  %s\n", $row->cnt, $row->category_name);
}

echo "\n--- By manufacturer (active) ---\n";
$byMfr = (clone $active)
    ->selectRaw('COALESCE(NULLIF(TRIM(manufacturer), ""), "(blank)") as mfr_name, COUNT(*) as cnt')
    ->groupBy('mfr_name')
    ->orderByDesc('cnt')
    ->get();
foreach ($byMfr as $row) {
    echo sprintf("%4d  %s\n", $row->cnt, $row->mfr_name);
}

echo "\n--- Sparse rows (missing price_preorder OR product_name = sku) ---\n";
$sparse = (clone $active)
    ->where(function ($q): void {
        $q->whereNull('price_preorder')
            ->orWhereRaw('TRIM(product_name) = TRIM(sku)')
            ->orWhere('product_name', '');
    })
    ->count();
echo "sparse_count={$sparse}\n";

echo "\n--- Catalog overlap: active preorders whose SKU is in products table ---\n";
$catalogSkus = Product::query()->notArchived()->whereNotNull('sku')->pluck('sku')->map(fn ($s) => trim((string) $s))->filter()->unique()->values()->all();
$catalogSet = array_flip($catalogSkus);
$activeSkus = (clone $active)->pluck('sku')->map(fn ($s) => trim((string) $s))->all();
$inCatalog = 0;
$notInCatalog = 0;
foreach ($activeSkus as $sku) {
    if (isset($catalogSet[$sku])) {
        $inCatalog++;
    } else {
        $notInCatalog++;
    }
}
echo "in_catalog={$inCatalog} not_in_catalog={$notInCatalog}\n";

echo "\n--- User's 16-line search SKUs: DB state ---\n";
$searchSkus = [
    '0225768', '5059162', '5068593', '5074249', '5072193',
    '5058260', '5058006', '5063823', '5061575', '5057575',
    '5058266', '5055751', '5063052', '5057617', '5060780', '0186528',
];
foreach ($searchSkus as $sku) {
    $row = PlamodPreorder::query()->where('sku', $sku)->first(['sku', 'product_name', 'series', 'category', 'manufacturer', 'price_preorder', 'dropped_at']);
    if (! $row) {
        echo "{$sku}: NOT IN DB\n";

        continue;
    }
    echo sprintf(
        "%s: series=%s | category=%s | dropped=%s | price=%s | name=%s\n",
        $sku,
        $row->series ?: '(blank)',
        $row->category ?: '(blank)',
        $row->dropped_at ? 'yes' : 'no',
        $row->price_preorder ?? 'null',
        mb_substr((string) $row->product_name, 0, 50),
    );
}
