<?php

declare(strict_types=1);

$h = file_get_contents(__DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html');

// Series links in product rows / filters
preg_match_all('/search\?[^"]*series=([^"&]+)/', $h, $m);
$series = [];
foreach ($m[1] as $raw) {
    $name = urldecode($raw);
    $series[$name] = ($series[$name] ?? 0) + 1;
}
ksort($series);
echo "=== series= URLs found in manufacturer HTML ===\n";
foreach ($series as $name => $cnt) {
    if (preg_match('/SD|Pokémon|Pokemon|Silhouette|Sangokuden|World Heroes|BB|G Generation|EX-Standard/i', $name)) {
        echo "{$cnt}x | {$name}\n";
    }
}

// Search literal SD series names anywhere
$names = [
    'SD Cross Silhouette',
    'SD EX-Standard',
    'SD G Generation',
    'SD Sangokuden',
    'SD World Heroes',
    'SD Gundam Sangokuden Brave Battle Warriors',
    'SD BB',
    'Pokémon',
    'Pokemon',
    'SD Gundam',
];
echo "\n=== literal series string hits ===\n";
foreach ($names as $n) {
    echo substr_count($h, $n)." | {$n}\n";
}
