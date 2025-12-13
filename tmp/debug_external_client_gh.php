<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PriceResearch\Http\ExternalHtmlClient;

$client = app(ExternalHtmlClient::class);
$url = 'https://server.gundamhangar.com/api/products?limit=16&page=1&category=gundam-mobile-suit-kit&outofstock=1&search=5068840';

try {
  $res = $client->get($url, ['Accept' => 'application/json, text/plain, */*']);
  echo "status={$res->status()}\n";
  echo substr($res->body(), 0, 200) . "\n";
} catch (Throwable $e) {
  echo "ERR: {$e->getMessage()}\n";
}