<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\PurchaseOrderLinesExportService;

/**
 * One-off merged vendor order CSV for PM broker placement.
 *
 * @param  array<int, string>  $purchaseOrderUuids
 * @return array{path: string, rows: int, totals: array<string, float>}
 */
function exportMergedVendorOrderCsv(array $purchaseOrderUuids, string $outputPath): array
{
    $exportService = app(PurchaseOrderLinesExportService::class);
    $ref = new ReflectionClass($exportService);
    $unitCadMethod = $ref->getMethod('productCostUnitCad');
    $unitCadMethod->setAccessible(true);
    $unitHkdMethod = $ref->getMethod('productCostUnitHkd');
    $unitHkdMethod->setAccessible(true);
    $fxMethod = $ref->getMethod('fxRateToCadForExport');
    $fxMethod->setAccessible(true);

    $headers = [
        'Vendor',
        'Product Name',
        'SKU',
        'Qty',
        'Unit cost (CAD)',
        'Shipping cost (CAD)',
        'Total cost (CAD)',
        'Unit cost (HKD)',
        'Shipping cost (HKD)',
        'Total cost (HKD)',
    ];

    $rows = [];

    foreach ($purchaseOrderUuids as $uuid) {
        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::query()->where('uuid', $uuid)->with(['items.product'])->first();
        if ($po === null) {
            throw new RuntimeException("Purchase order not found: {$uuid}");
        }

        $exportServiceForPo = app(PurchaseOrderLinesExportService::class);
        $poRef = new ReflectionClass($exportServiceForPo);
        $unitCadPo = $poRef->getMethod('productCostUnitCad');
        $unitCadPo->setAccessible(true);
        $unitHkdPo = $poRef->getMethod('productCostUnitHkd');
        $unitHkdPo->setAccessible(true);
        $fxPo = $poRef->getMethod('fxRateToCadForExport');
        $fxPo->setAccessible(true);

        $headerProduct = (float) ($po->product_total ?? 0);
        $headerShipping = (float) ($po->shipping_total ?? 0);

        /** @var list array{0: PurchaseOrderItem, 1: string|null}> $pricedItems */
        $pricedItems = [];
        $lineProductTotal = 0.0;

        foreach ($po->items as $item) {
            if (! $item instanceof PurchaseOrderItem) {
                continue;
            }

            $qty = (int) ($item->qty_ordered ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitCad = $unitCadPo->invoke($exportServiceForPo, $po, $item);
            if ($unitCad === null) {
                continue;
            }

            $lineProductTotal += (float) $unitCad * $qty;
            $pricedItems[] = [$item, $unitCad];
        }

        $productScale = ($headerProduct > 0 && $lineProductTotal > 0)
            ? $headerProduct / $lineProductTotal
            : 1.0;
        $shippingRatio = $headerProduct > 0 ? $headerShipping / $headerProduct : 0.0;
        $fxToCad = $exportServiceForPo->isHkdBrokerVendor($po->vendor)
            ? $fxPo->invoke($exportServiceForPo, $po)
            : null;

        foreach ($pricedItems as [$item, $rawUnitCad]) {
            $qty = (int) ($item->qty_ordered ?? 0);
            $unitCad = money2((string) round((float) $rawUnitCad * $productScale, 2));

            $unitHkd = null;
            if ($exportServiceForPo->isHkdBrokerVendor($po->vendor)) {
                $rawUnitHkd = $unitHkdPo->invoke($exportServiceForPo, $po, $item);
                $unitHkd = $rawUnitHkd !== null
                    ? money2((string) round((float) $rawUnitHkd * $productScale, 2))
                    : ($unitCad !== null && $fxToCad !== null && (float) $fxToCad > 0
                        ? money2((string) round((float) $unitCad / (float) $fxToCad, 2))
                        : null);
            }

            $shippingCadPerUnit = $unitCad !== null
                ? (float) $unitCad * $shippingRatio
                : null;
            $lineProductCad = $unitCad !== null
                ? mulLine((float) $unitCad, $qty)
                : null;
            $lineShippingCad = $shippingCadPerUnit !== null
                ? mulLine($shippingCadPerUnit, $qty)
                : null;
            $lineTotalCad = ($lineProductCad !== null && $lineShippingCad !== null)
                ? money2((string) round((float) $lineProductCad + (float) $lineShippingCad, 2))
                : $lineProductCad;

            $lineShippingHkd = null;
            $lineTotalHkd = null;
            if ($unitHkd !== null) {
                $lineProductHkd = mulLine((float) $unitHkd, $qty);
                if ($shippingCadPerUnit !== null && $fxToCad !== null && (float) $fxToCad > 0) {
                    $lineShippingHkd = mulLine((float) $shippingCadPerUnit / (float) $fxToCad, $qty);
                } elseif ($shippingRatio > 0) {
                    $lineShippingHkd = mulLine((float) $unitHkd * $shippingRatio, $qty);
                }
                $lineTotalHkd = $lineShippingHkd !== null
                    ? money2((string) round((float) $lineProductHkd + (float) $lineShippingHkd, 2))
                    : $lineProductHkd;
            }

            $rows[] = [
                $po->vendor,
                (string) ($item->product_name ?? $item->product?->description ?? ''),
                (string) $item->sku,
                (string) $qty,
                $unitCad ?? '',
                $lineShippingCad ?? '',
                $lineTotalCad ?? '',
                $unitHkd ?? '',
                $lineShippingHkd ?? '',
                $lineTotalHkd ?? '',
            ];
        }
    }

    usort($rows, static fn (array $a, array $b): int => strcasecmp($a[0], $b[0]) ?: strcasecmp($a[2], $b[2]));

    @mkdir(dirname($outputPath), 0775, true);
    $fh = fopen($outputPath, 'wb');
    if ($fh === false) {
        throw new RuntimeException("Could not open {$outputPath} for writing.");
    }

    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $headers);
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);

    $totals = [
        'product_cad' => 0.0,
        'shipping_cad' => 0.0,
        'landed_cad' => 0.0,
    ];
    foreach ($rows as $row) {
        $qty = (float) $row[3];
        if ($row[4] !== '') {
            $totals['product_cad'] += (float) $row[4] * $qty;
        }
        if ($row[5] !== '') {
            $totals['shipping_cad'] += (float) $row[5];
        }
        if ($row[6] !== '') {
            $totals['landed_cad'] += (float) $row[6];
        }
    }

    return ['path' => $outputPath, 'rows' => count($rows), 'totals' => $totals];
}

function money2(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    if ($trimmed === '' || ! is_numeric($trimmed)) {
        return null;
    }

    return number_format((float) $trimmed, 2, '.', '');
}

function mulLine(float $unit, int $qty): string
{
    return money2((string) round($unit * $qty, 2)) ?? '0.00';
}

$uuids = [
    'e4d11d64-e0ee-4917-acbf-c9cf8b8769da', // Dspiae draft
    '48abd4c0-4cd7-483a-8ffe-d09bb45d137b', // Stedi draft
];

$outputPath = __DIR__.'/storage/app/private/exports/vendor-order-merged-20260729.csv';
$result = exportMergedVendorOrderCsv($uuids, $outputPath);

echo "Wrote {$result['path']}\n";
echo 'Rows: '.$result['rows']."\n";
echo 'Computed product CAD: '.number_format($result['totals']['product_cad'], 2, '.', '')."\n";
echo 'Computed shipping CAD: '.number_format($result['totals']['shipping_cad'], 2, '.', '')."\n";
echo 'Computed landed CAD: '.number_format($result['totals']['landed_cad'], 2, '.', '')."\n";

foreach ($uuids as $uuid) {
    $po = PurchaseOrder::query()->where('uuid', $uuid)->first();
    if ($po === null) {
        continue;
    }
    $headerTotal = (float) ($po->product_total ?? 0) + (float) ($po->shipping_total ?? 0);
    echo "PO {$po->vendor} header total CAD: ".number_format($headerTotal, 2, '.', '')."\n";
}
