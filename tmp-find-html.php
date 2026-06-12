<?php

$h = file_get_contents(__DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html');
foreach ([
    'Mobile Suit Gundam',
    'Gundam F90-F91',
    'Sgt. Keroro',
    '30 Minutes Missions',
] as $n) {
    $p = strpos($h, $n);
    echo $n.' => '.($p === false ? 'none' : $p)."\n";
    if ($p !== false) {
        echo substr($h, $p - 60, 140)."\n\n";
    }
}
