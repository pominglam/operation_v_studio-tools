<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || ! is_readable($path)) {
    fwrite(STDERR, "Usage: php shipping-allocation-summarize.php <csv-path>\n");
    exit(1);
}

$rows = array_map('str_getcsv', file($path));
$header = array_shift($rows);
$idx = array_flip($header);

$data = [];
foreach ($rows as $r) {
    if (($r[$idx['sku']] ?? '') === '' || ($r[0] ?? '') === 'CHECK') {
        continue;
    }

    $delta = (float) ($r[$idx['landed_per_unit_delta']] ?? 0);
    $data[] = [
        'sku' => $r[$idx['sku']],
        'name' => $r[$idx['name']],
        'qty' => $r[$idx['qty']],
        'unit' => $r[$idx['unit_cost']],
        'cur' => $r[$idx['landed_per_unit_current']],
        'new' => $r[$idx['landed_per_unit_new']],
        'delta' => $r[$idx['landed_per_unit_delta']],
        'pct' => $r[$idx['landed_pct_change']],
        'abs' => abs($delta),
        'delta_f' => $delta,
    ];
}

usort($data, static fn (array $a, array $b): int => $b['abs'] <=> $a['abs']);

printSection('BIGGEST INCREASES (expensive items — currently under-allocated shipping)', array_filter($data, static fn (array $d): bool => $d['delta_f'] >= 0.10));
printSection('BIGGEST DECREASES (cheap/high-qty items — currently over-allocated shipping)', array_filter($data, static fn (array $d): bool => $d['delta_f'] <= -0.10));

/** @param array<int, array<string, mixed>> $items */
function printSection(string $title, array $items): void
{
    $items = array_values($items);
    usort($items, static fn (array $a, array $b): int => $b['abs'] <=> $a['abs']);

    echo "\n=== {$title} ===\n";
    if ($items === []) {
        echo "(none >= \$0.10)\n";

        return;
    }

    foreach (array_slice($items, 0, 15) as $d) {
        $name = strlen((string) $d['name']) > 48 ? substr((string) $d['name'], 0, 45).'...' : $d['name'];
        echo sprintf(
            "%-12s qty=%-3s unit=%-8s landed %s -> %s (%s, %s%%) %s\n",
            $d['sku'],
            $d['qty'],
            $d['unit'],
            $d['cur'],
            $d['new'],
            $d['delta'],
            $d['pct'],
            $name,
        );
    }
}
