<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Services\PriceResearch\Providers\MeeplemartProvider;

$product = Product::query()->where('sku', '5060358')->first();
if ($product === null) {
    fwrite(STDERR, "product not found\n");
    exit(1);
}

$provider = app(MeeplemartProvider::class);
$res = $provider->lookup($product);

var_export([
    'status' => $res->status,
    'price' => $res->price,
    'productUrl' => $res->productUrl,
    'availability' => $res->availability,
    'error' => $res->errorMessage,
]);
echo "\n";
