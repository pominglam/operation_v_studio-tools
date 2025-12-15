<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$product = App\Models\Product::query()->where('sku', '5060358')->first();
$prov = app(App\Services\PriceResearch\Providers\HobbySenseProvider::class);
$r = $prov->lookup($product);

var_export([
    'status' => $r->status,
    'price' => $r->price,
    'originalPrice' => $r->originalPrice,
    'url' => $r->productUrl,
    'availability' => $r->availability,
    'error' => $r->errorMessage,
]);
echo "\n";
