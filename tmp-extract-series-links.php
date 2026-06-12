<?php

declare(strict_types=1);

$htmlPath = __DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html';
$h = file_get_contents($htmlPath);

preg_match_all('/href="\/retailer\/search\?series=([^"]+)"[^>]*>([^<]+)</', $h, $m, PREG_SET_ORDER);
$series = [];
foreach ($m as $match) {
    $name = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5);
    $param = urldecode($match[1]);
    $series[$name] = $param;
}

ksort($series);
echo "=== SERIES links on manufacturer preorder view ===\n";
echo 'count='.count($series)."\n";
foreach ($series as $name => $param) {
    echo $name.' | '.$param."\n";
}
