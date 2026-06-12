<?php

declare(strict_types=1);

$h = file_get_contents(__DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html');

$names = [
    '30 Minutes Fantasy (30MF)',
    '30 Minutes Missions (30MM)',
    'After War Gundam X',
    'ARMORED CORE',
    'Mobile Suit Gundam',
    'Gundam F90-F91',
];

foreach ($names as $name) {
    $escaped = str_replace("'", "\\'", $name);
    $p = strpos($h, $name);
    $p2 = strpos($h, str_replace("'", '\\u0027', $name));
    echo "{$name}: plain=".($p === false ? 'no' : $p).' escaped='.($p2 === false ? 'no' : $p2)."\n";
    if ($p !== false) {
        echo '  '.substr($h, max(0, $p - 30), 120)."\n";
    }
}

// Find all productCount occurrences with nearby series name
if (preg_match_all('/\\"name\\":\\"((?:\\\\.|[^\\\\"])*)\\",\\"trending\\":(?:true|false),\\"productCount\\":(\d+)/', $h, $m, PREG_SET_ORDER)) {
    echo "\n=== series entries with productCount (embedded JSON) ===\n";
    echo 'count='.count($m)."\n";
    foreach (array_slice($m, 0, 20) as $x) {
        echo $x[2].' | '.stripcslashes($x[1])."\n";
    }
}
