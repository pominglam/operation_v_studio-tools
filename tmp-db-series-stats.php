<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PlamodPreorder;
use App\Models\Product;

$catalog = array_flip(
    Product::query()->notArchived()->whereNotNull('sku')->pluck('sku')->map(fn ($s) => trim((string) $s))->all()
);

$dbSeries = PlamodPreorder::query()
    ->active()
    ->where('manufacturer', 'BANDAI HOBBY')
    ->selectRaw('COALESCE(NULLIF(TRIM(series), ""), "(blank)") as s, COUNT(*) as c')
    ->groupBy('s')
    ->orderByDesc('c')
    ->pluck('c', 's');

echo "=== BANDAI HOBBY active rows by series (full/sparse/in_catalog) ===\n";
foreach ($dbSeries as $series => $cnt) {
    $q = PlamodPreorder::query()->active()->where('manufacturer', 'BANDAI HOBBY');
    if ($series === '(blank)') {
        $q->where(function ($sub): void {
            $sub->whereNull('series')->orWhere('series', '');
        });
    } else {
        $q->where('series', $series);
    }
    $full = 0;
    $sparse = 0;
    $inCat = 0;
    foreach ($q->get(['sku', 'product_name', 'price_preorder']) as $r) {
        if ($r->price_preorder && $r->product_name && $r->product_name !== $r->sku) {
            $full++;
        } else {
            $sparse++;
        }
        if (isset($catalog[trim((string) $r->sku)])) {
            $inCat++;
        }
    }
    echo sprintf("%3d total | full=%3d sparse=%3d in_catalog=%2d | %s\n", $cnt, $full, $sparse, $inCat, $series);
}

// Non-Bandai categories we probably should NOT series-pull from manufacturer page
echo "\n=== Non-Bandai manufacturers in active import (skip for Bandai series pull) ===\n";
$others = PlamodPreorder::query()
    ->active()
    ->where(function ($q): void {
        $q->whereNull('manufacturer')->orWhere('manufacturer', '!=', 'BANDAI HOBBY');
    })
    ->selectRaw('COALESCE(NULLIF(TRIM(manufacturer), ""), "(blank)") as m, COUNT(*) as c')
    ->groupBy('m')
    ->orderByDesc('c')
    ->get();
foreach ($others as $row) {
    echo sprintf("%4d  %s\n", $row->c, $row->m);
}
