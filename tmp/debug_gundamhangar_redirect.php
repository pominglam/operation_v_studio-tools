<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$url = 'https://server.gundamhangar.com/api/products?limit=16&page=1&category=gundam-mobile-suit-kit&outofstock=1&search=5068840';

$res = Http::withOptions(['allow_redirects' => false])
    ->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        'Accept' => 'application/json, text/plain, */*',
        'Accept-Language' => 'en-CA,en;q=0.9',
    ])
    ->get($url);

echo "status={$res->status()}\n";
foreach (['location', 'Location', 'set-cookie', 'Set-Cookie', 'server', 'Server'] as $h) {
    $v = $res->header($h);
    if ($v !== null) {
        echo $h.'='.$v."\n";
    }
}

$body = $res->body();
echo 'body_prefix='.substr($body, 0, 200)."\n";
