<?php

declare(strict_types=1);

$htmlPath = __DIR__.'/storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html';
$h = file_get_contents($htmlPath);

function parseFilterSection(string $haystack, string $key): array
{
    $marker = '\"'.$key.'\":';
    $pos = strpos($haystack, $marker);
    if ($pos === false) {
        return [];
    }

    $slice = substr($haystack, $pos + strlen($marker));
    $start = strpos($slice, '[');
    if ($start === false) {
        return [];
    }

    $depth = 0;
    $end = null;
    $len = strlen($slice);
    for ($i = $start; $i < $len; $i++) {
        $ch = $slice[$i];
        if ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }

    if ($end === null) {
        return [];
    }

    $raw = substr($slice, $start + 1, $end - $start - 1);
    $chunks = preg_split('/\},\{/', $raw) ?: [];
    $items = [];
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk, '{}');
        $idPos = strpos($chunk, 'id\":');
        $namePos = strpos($chunk, 'name\":\"');
        if ($idPos === false || $namePos === false) {
            continue;
        }
        $idStart = $idPos + 5;
        $idEnd = strpos($chunk, ',', $idStart);
        $id = substr($chunk, $idStart, $idEnd - $idStart);
        $nameStart = $namePos + 8;
        $nameEnd = strrpos($chunk, '\"');
        $name = substr($chunk, $nameStart, $nameEnd - $nameStart);
        $items[] = ['id' => $id, 'name' => $name];
    }

    return $items;
}

$categories = parseFilterSection($h, 'categories');
$series = parseFilterSection($h, 'series');

usort($categories, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
usort($series, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

echo "=== CATEGORY filters (product-line tab) ===\n";
echo 'count='.count($categories)."\n";
foreach ($categories as $x) {
    echo "{$x['id']} | {$x['name']}\n";
}

echo "\n=== SERIES tab filters ===\n";
echo 'count='.count($series)."\n";
foreach ($series as $x) {
    echo "{$x['id']} | {$x['name']}\n";
}
