<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\PriceResearch\Support\HtmlPriceParser;

$url = 'https://www.meeplemart.com/store/Search.aspx?SearchTerms=HG%201%2F144%20%2313%20Gundam%20Astray%20Blue%20Frame';
$ctx = stream_context_create([
    'http' => [
        'header' => "User-Agent: Mozilla/5.0\r\n",
    ],
]);

$html = file_get_contents($url, false, $ctx);
if ($html === false) {
    fwrite(STDERR, "fetch failed\n");
    exit(1);
}

$parser = new HtmlPriceParser();
$urls = $parser->extractCandidateProductUrls($html, 'https://www.meeplemart.com');

fwrite(STDOUT, "urls=" . count($urls) . "\n");
foreach ($urls as $u) {
    fwrite(STDOUT, $u . "\n");
}