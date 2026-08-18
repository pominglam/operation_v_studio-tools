<?php

declare(strict_types=1);

/**
 * One-off analysis: compare current per-unit shipping allocation vs pro-rata by line extended cost.
 *
 * Usage (inside pricing-tool-php container):
 *   php scripts/shipping-allocation-comparison.php [--po-id=72] [--out=path.csv]
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\PurchaseOrders\PurchaseOrderAllocation;
use Illuminate\Support\Facades\DB;

$poId = 72;
$outPath = __DIR__.'/../storage/app/shipping-allocation-sample-po-72.csv';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--po-id=')) {
        $poId = (int) substr($arg, 8);
    }
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    }
}

/** @var object|null $po */
$po = DB::table('purchase_orders')->where('id', $poId)->first();
if ($po === null) {
    fwrite(STDERR, "PO {$poId} not found\n");
    exit(1);
}

$lines = DB::table('purchase_order_items as poi')
    ->leftJoin('products as p', 'p.id', '=', 'poi.product_id')
    ->where('poi.purchase_order_id', $poId)
    ->orderBy('poi.id')
    ->get([
        'poi.sku',
        'p.description as name',
        'poi.qty_ordered',
        'poi.qty_received',
        'poi.unit_cost',
    ]);

if ($lines->isEmpty()) {
    fwrite(STDERR, "PO {$poId} has no lines\n");
    exit(1);
}

$sumReceived = 0;
$sumOrdered = 0;
$receivedEntriesCount = 0;
$poProductTotalCents = 0;

foreach ($lines as $line) {
    $qtyOrdered = (int) ($line->qty_ordered ?? 0);
    $sumOrdered += $qtyOrdered;
    if ($line->qty_received !== null) {
        $receivedEntriesCount++;
        $sumReceived += (int) $line->qty_received;
    }

    $unitCents = moneyToCents($line->unit_cost);
    $qtyForExtended = qtyForLine($line);
    if ($unitCents !== null && $qtyForExtended > 0) {
        $poProductTotalCents += $unitCents * $qtyForExtended;
    }
}

$units = PurchaseOrderAllocation::unitsFromTotals($sumReceived, $sumOrdered, $receivedEntriesCount);
$shippingTotalCents = moneyToCents($po->shipping_total) ?? 0;
$surchargeTotalCents = moneyToCents($po->surcharge_total) ?? 0;

$currentShipPerUnitCents = $units > 0
    ? intdiv($shippingTotalCents + intdiv($units, 2), $units)
    : 0;
$currentSurchargePerUnitCents = $units > 0
    ? intdiv($surchargeTotalCents + intdiv($units, 2), $units)
    : 0;

$rows = [];
$newLineShippingCents = [];

foreach ($lines as $index => $line) {
    $qty = qtyForLine($line);
    $unitCents = moneyToCents($line->unit_cost) ?? 0;
    $extendedCents = $unitCents * $qty;

    $currentLineShipCents = $currentShipPerUnitCents * $qty;
    $currentLineSurchargeCents = $currentSurchargePerUnitCents * $qty;

    $ratio = $poProductTotalCents > 0 ? ($extendedCents / $poProductTotalCents) : 0.0;
    $proposedLineShipCents = $poProductTotalCents > 0
        ? (int) round($shippingTotalCents * $ratio)
        : 0;
    $proposedLineSurchargeCents = $poProductTotalCents > 0
        ? (int) round($surchargeTotalCents * $ratio)
        : 0;

    $newLineShippingCents[$index] = $proposedLineShipCents;
    $newLineSurchargeCents[$index] = $proposedLineSurchargeCents;

    $rows[] = [
        'index' => $index,
        'sku' => (string) $line->sku,
        'name' => (string) ($line->name ?? ''),
        'qty' => $qty,
        'unit_cost' => centsToMoney($unitCents),
        'line_product_cost' => centsToMoney($extendedCents),
        'cost_ratio_pct' => number_format($ratio * 100, 4),
        'shipping_per_unit_current' => centsToMoney($currentShipPerUnitCents),
        'shipping_line_total_current' => centsToMoney($currentLineShipCents),
        'shipping_per_unit_new_raw' => $qty > 0 ? centsToMoney((int) round($proposedLineShipCents / $qty)) : '0.00',
        'shipping_line_total_new_raw' => centsToMoney($proposedLineShipCents),
        'surcharge_line_total_current' => centsToMoney($currentLineSurchargeCents),
        'surcharge_line_total_new_raw' => centsToMoney($proposedLineSurchargeCents),
        'landed_per_unit_current' => centsToMoney($unitCents + $currentShipPerUnitCents + $currentSurchargePerUnitCents),
        'landed_per_unit_new_raw' => $qty > 0
            ? centsToMoney($unitCents + (int) round($proposedLineShipCents / $qty) + (int) round($proposedLineSurchargeCents / $qty))
            : centsToMoney($unitCents),
        'shipping_per_unit_delta_raw' => $qty > 0
            ? centsToMoney((int) round($proposedLineShipCents / $qty) - $currentShipPerUnitCents)
            : '0.00',
    ];
}

adjustPennyDrift($newLineShippingCents, $shippingTotalCents);
adjustPennyDrift($newLineSurchargeCents, $surchargeTotalCents);

foreach ($rows as &$row) {
    $idx = $row['index'];
    unset($row['index']);
    $qty = (int) $row['qty'];
    $unitCents = moneyToCents($row['unit_cost']) ?? 0;
    $lineShipNew = $newLineShippingCents[$idx];
    $lineSurchargeNew = $newLineSurchargeCents[$idx];
    $shipPerUnitNew = $qty > 0 ? intdiv($lineShipNew + intdiv($qty, 2), $qty) : 0;
    $surchargePerUnitNew = $qty > 0 ? intdiv($lineSurchargeNew + intdiv($qty, 2), $qty) : 0;

    $row['shipping_per_unit_new'] = centsToMoney($shipPerUnitNew);
    $row['shipping_line_total_new'] = centsToMoney($lineShipNew);
    $row['surcharge_line_total_new'] = centsToMoney($lineSurchargeNew);
    $landedCurrentCents = moneyToCents($row['landed_per_unit_current']) ?? 0;
    $landedNewCents = $unitCents + $shipPerUnitNew + $surchargePerUnitNew;
    $landedDeltaCents = $landedNewCents - $landedCurrentCents;

    $row['landed_per_unit_new'] = centsToMoney($landedNewCents);
    $row['shipping_per_unit_delta'] = centsToMoney($shipPerUnitNew - moneyToCents($row['shipping_per_unit_current']));
    $row['landed_per_unit_delta'] = centsToMoney($landedDeltaCents);
    $row['landed_pct_change'] = $landedCurrentCents > 0
        ? number_format(($landedDeltaCents / $landedCurrentCents) * 100, 2)
        : '0.00';
    $row['landed_delta_cents_abs'] = abs($landedDeltaCents);
    unset($row['shipping_per_unit_new_raw'], $row['shipping_line_total_new_raw'], $row['surcharge_line_total_new_raw'], $row['landed_per_unit_new_raw'], $row['shipping_per_unit_delta_raw']);
}
unset($row);

$headers = [
    'po_id',
    'po_uuid',
    'po_vendor',
    'po_shipping_total',
    'po_surcharge_total',
    'po_product_total_calc',
    'allocation_units',
    'sku',
    'name',
    'qty',
    'unit_cost',
    'line_product_cost',
    'cost_ratio_pct',
    'shipping_per_unit_current',
    'shipping_line_total_current',
    'shipping_per_unit_new',
    'shipping_line_total_new',
    'shipping_per_unit_delta',
    'surcharge_line_total_current',
    'surcharge_line_total_new',
    'landed_per_unit_current',
    'landed_per_unit_new',
    'landed_per_unit_delta',
    'landed_pct_change',
];

$fp = fopen($outPath, 'w');
if ($fp === false) {
    fwrite(STDERR, "Cannot write {$outPath}\n");
    exit(1);
}

fputcsv($fp, $headers);

$sumCurrentShip = 0;
$sumNewShip = 0;

foreach ($rows as $row) {
    $sumCurrentShip += moneyToCents($row['shipping_line_total_current']) ?? 0;
    $sumNewShip += moneyToCents($row['shipping_line_total_new']) ?? 0;

    fputcsv($fp, [
        $poId,
        (string) $po->uuid,
        (string) $po->vendor,
        centsToMoney($shippingTotalCents),
        centsToMoney($surchargeTotalCents),
        centsToMoney($poProductTotalCents),
        $units,
        $row['sku'],
        $row['name'],
        $row['qty'],
        $row['unit_cost'],
        $row['line_product_cost'],
        $row['cost_ratio_pct'],
        $row['shipping_per_unit_current'],
        $row['shipping_line_total_current'],
        $row['shipping_per_unit_new'],
        $row['shipping_line_total_new'],
        $row['shipping_per_unit_delta'],
        $row['surcharge_line_total_current'],
        $row['surcharge_line_total_new'],
        $row['landed_per_unit_current'],
        $row['landed_per_unit_new'],
        $row['landed_per_unit_delta'],
        $row['landed_pct_change'],
    ]);
}

fputcsv($fp, []);
fputcsv($fp, ['CHECK', '', '', 'po_shipping_total', centsToMoney($shippingTotalCents), 'sum_current_line_shipping', centsToMoney($sumCurrentShip), 'sum_new_line_shipping', centsToMoney($sumNewShip)]);

fclose($fp);

echo "Wrote {$outPath}\n";
echo "PO {$poId} ({$po->vendor}): {$lines->count()} lines, shipping ".centsToMoney($shippingTotalCents).", units {$units}\n";
echo 'Current allocated shipping sum: '.centsToMoney($sumCurrentShip)." (method: even per unit)\n";
echo 'New pro-rata shipping sum: '.centsToMoney($sumNewShip)." (method: extended cost ratio)\n";

usort($rows, static fn (array $a, array $b): int => ($b['landed_delta_cents_abs'] ?? 0) <=> ($a['landed_delta_cents_abs'] ?? 0));

$drastic = array_values(array_filter($rows, static fn (array $r): bool => ($r['landed_delta_cents_abs'] ?? 0) >= 10));
$top = array_slice($drastic, 0, 20);

if ($top !== []) {
    echo "\nTop landed-cost swings (|delta| >= \$0.10), sorted by magnitude:\n";
    echo str_pad('SKU', 14)
        .str_pad('Qty', 5)
        .str_pad('Unit$', 8)
        .str_pad('Landed cur', 12)
        .str_pad('Landed new', 12)
        .str_pad('Delta', 8)
        .str_pad('% chg', 8)
        ."Name\n";

    foreach ($top as $row) {
        $name = strlen($row['name']) > 42 ? substr($row['name'], 0, 39).'...' : $row['name'];
        echo str_pad($row['sku'], 14)
            .str_pad((string) $row['qty'], 5)
            .str_pad($row['unit_cost'], 8)
            .str_pad($row['landed_per_unit_current'], 12)
            .str_pad($row['landed_per_unit_new'], 12)
            .str_pad($row['landed_per_unit_delta'], 8)
            .str_pad($row['landed_pct_change'].'%', 8)
            .$name."\n";
    }
} else {
    echo "\nNo lines with landed delta >= \$0.10.\n";
}

function qtyForLine(object $line): int
{
    if ($line->qty_received !== null) {
        return (int) $line->qty_received;
    }

    return (int) ($line->qty_ordered ?? 0);
}

function moneyToCents(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    $s = trim((string) $value);
    if ($s === '') {
        return null;
    }

    $clean = preg_replace('/[^0-9\.\-]/', '', $s) ?? '';
    if ($clean === '' || $clean === '-' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
        return null;
    }

    $neg = str_starts_with($clean, '-');
    if ($neg) {
        $clean = substr($clean, 1);
    }

    [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '');
    $whole = $whole === '' ? '0' : $whole;
    $f = str_pad($frac, 3, '0');
    $cents = ((int) $whole) * 100 + (int) substr($f, 0, 2);
    if ((int) ($f[2] ?? '0') >= 5) {
        $cents++;
    }

    return $neg ? -$cents : $cents;
}

function centsToMoney(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    $dollars = intdiv($cents, 100);
    $rem = $cents % 100;

    return $sign.$dollars.'.'.str_pad((string) $rem, 2, '0', STR_PAD_LEFT);
}

/** @param array<int, int> $lineTotalsCents */
function adjustPennyDrift(array &$lineTotalsCents, int $targetTotalCents): void
{
    if ($lineTotalsCents === []) {
        return;
    }

    $sum = array_sum($lineTotalsCents);
    $drift = $targetTotalCents - $sum;
    if ($drift === 0) {
        return;
    }

    $idx = array_key_last($lineTotalsCents);
    $lineTotalsCents[$idx] += $drift;
}
