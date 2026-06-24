<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$resolver = new App\Support\Products\Storefront\ToolFamilyProductResolver;

foreach (['MS-11', 'MS-161', 'MS-12', 'MS-17', 'MS-163', 'MS-14', 'MS-160'] as $sku) {
    $product = App\Models\Product::query()->where('sku', $sku)->first();
    if ($product === null) {
        continue;
    }
    $desc = strtolower((string) $product->description);
    $hasCurve = str_contains($desc, 'curve') ? 'YES' : 'no';
    echo $sku.' | curve_substr='.$hasCurve.' | style='.$resolver->resolveTweezerStyle($product).' | '.$product->description."\n";
}
