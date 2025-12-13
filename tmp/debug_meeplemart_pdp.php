<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\PriceResearch\Support\HtmlPriceParser;

$url = 'https://www.meeplemart.com/gundam-seed-msv-series-hg-1/144-13-gundam-astray-blue-frame.aspx';
$ctx = stream_context_create([
    'http' => [
        'header' => "User-Agent: Mozilla/5.0\r\nAccept: text/html\r\n",
    ],
]);
$html = file_get_contents($url, false, $ctx);
if ($html === false) {
    fwrite(STDERR, "fetch failed\n");
    exit(1);
}

$parser = new HtmlPriceParser();
$out = $parser->extractPriceAndAvailabilityFromHtml($html);

var_export($out);
echo "\n";