<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Models\PurchaseOrderItem;

final class PurchaseOrderLineMergeService
{
    /**
     * @param  array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>  $rows
     * @return array{
     *   rows: array<int, array{
     *     row:int,
     *     sku:string,
     *     unit_cost:string|null,
     *     qty_ordered:int|null,
     *     qty_shipped:int|null,
     *     qty_received:int|null,
     *     product_name:string|null,
     *     barcode:string|null,
     *     vendor_line_total?:string|null
     *   }>,
     *   merged_count:int
     * }
     */
    public function mergeParsedImportRows(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $bySku */
        $bySku = [];
        $mergedCount = 0;

        foreach ($rows as $row) {
            $sku = $this->normalizeSku((string) $row['sku']);
            if ($sku === '') {
                continue;
            }

            if (! isset($bySku[$sku])) {
                $bySku[$sku] = $row;
                $bySku[$sku]['sku'] = $sku;

                continue;
            }

            $bySku[$sku] = $this->mergeParsedImportRow($bySku[$sku], $row);
            $mergedCount++;
        }

        return [
            'rows' => array_values($bySku),
            'merged_count' => $mergedCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $accumulator
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function mergeParsedImportRow(array $accumulator, array $incoming): array
    {
        return [
            'row' => min((int) $accumulator['row'], (int) $incoming['row']),
            'sku' => (string) $accumulator['sku'],
            'product_name' => $this->firstNonEmptyString(
                isset($accumulator['product_name']) ? (string) ($accumulator['product_name'] ?? '') : '',
                isset($incoming['product_name']) ? (string) ($incoming['product_name'] ?? '') : '',
            ),
            'barcode' => $this->firstNonEmptyString(
                isset($accumulator['barcode']) ? (string) ($accumulator['barcode'] ?? '') : '',
                isset($incoming['barcode']) ? (string) ($incoming['barcode'] ?? '') : '',
            ),
            'qty_ordered' => $this->sumNullableInts(
                isset($accumulator['qty_ordered']) ? $this->nullableInt($accumulator['qty_ordered']) : null,
                isset($incoming['qty_ordered']) ? $this->nullableInt($incoming['qty_ordered']) : null,
            ),
            'qty_shipped' => $this->sumNullableInts(
                isset($accumulator['qty_shipped']) ? $this->nullableInt($accumulator['qty_shipped']) : null,
                isset($incoming['qty_shipped']) ? $this->nullableInt($incoming['qty_shipped']) : null,
            ),
            'qty_received' => $this->sumNullableInts(
                isset($accumulator['qty_received']) ? $this->nullableInt($accumulator['qty_received']) : null,
                isset($incoming['qty_received']) ? $this->nullableInt($incoming['qty_received']) : null,
            ),
            'unit_cost' => $this->weightedAverageDecimal([
                ['cost' => isset($accumulator['unit_cost']) ? $this->nullableDecimal($accumulator['unit_cost']) : null, 'weight' => $this->weightForAverage($accumulator)],
                ['cost' => isset($incoming['unit_cost']) ? $this->nullableDecimal($incoming['unit_cost']) : null, 'weight' => $this->weightForAverage($incoming)],
            ]),
            'vendor_line_total' => $this->sumNullableDecimals(
                isset($accumulator['vendor_line_total']) ? $this->nullableDecimal($accumulator['vendor_line_total']) : null,
                isset($incoming['vendor_line_total']) ? $this->nullableDecimal($incoming['vendor_line_total']) : null,
            ),
        ];
    }

    /**
     * @param  array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }  $row
     */
    public function mergeImportRowIntoItem(PurchaseOrderItem $item, array $row): void
    {
        $existingWeight = $this->weightForItem($item);
        $incomingWeight = $this->weightForAverage($row);

        $item->qty_ordered = $this->sumNullableInts(
            $item->qty_ordered !== null ? (int) $item->qty_ordered : null,
            $row['qty_ordered'],
        );
        $item->qty_shipped = $this->sumNullableInts(
            $item->qty_shipped !== null ? (int) $item->qty_shipped : null,
            $row['qty_shipped'],
        );
        $item->qty_received = $this->sumNullableInts(
            $item->qty_received !== null ? (int) $item->qty_received : null,
            $row['qty_received'],
        );

        $item->unit_cost = $this->weightedAverageDecimal([
            ['cost' => $item->unit_cost !== null ? (string) $item->unit_cost : null, 'weight' => $existingWeight],
            ['cost' => $row['unit_cost'], 'weight' => $incomingWeight],
        ]);
    }

    /**
     * @param  list<PurchaseOrderItem>  $items
     */
    public function mergeDuplicateItemsOntoSurvivor(PurchaseOrderItem $survivor, array $items): void
    {
        $all = [$survivor, ...$items];

        $unitCostPairs = [];
        $vendorCostPairs = [];
        foreach ($all as $item) {
            $weight = $this->weightForItem($item);
            $unitCostPairs[] = ['cost' => $item->unit_cost !== null ? (string) $item->unit_cost : null, 'weight' => $weight];
            $vendorCostPairs[] = ['cost' => $item->vendor_unit_cost !== null ? (string) $item->vendor_unit_cost : null, 'weight' => $weight];
        }

        $survivor->qty_ordered = $this->sumNullableInts(
            ...array_map(static fn (PurchaseOrderItem $i): ?int => $i->qty_ordered !== null ? (int) $i->qty_ordered : null, $all),
        );
        $survivor->qty_shipped = $this->sumNullableInts(
            ...array_map(static fn (PurchaseOrderItem $i): ?int => $i->qty_shipped !== null ? (int) $i->qty_shipped : null, $all),
        );
        $survivor->qty_received = $this->mergeQtyReceivedForDedup(
            ...array_map(static fn (PurchaseOrderItem $i): ?int => $i->qty_received !== null ? (int) $i->qty_received : null, $all),
        );

        $survivor->unit_cost = $this->weightedAverageDecimal($unitCostPairs);
        $survivor->vendor_unit_cost = $this->weightedAverageDecimal($vendorCostPairs);
    }

    public function groupKeyForItem(PurchaseOrderItem $item): string
    {
        $productId = (int) ($item->product_id ?? 0);
        if ($productId > 0) {
            return 'product:'.$productId;
        }

        return 'sku:'.$this->normalizeSku((string) $item->sku);
    }

    public function sumNullableInts(?int ...$values): ?int
    {
        $sum = 0;
        $hasAny = false;
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $hasAny = true;
            $sum += $value;
        }

        return $hasAny ? $sum : null;
    }

    public function mergeQtyReceivedForDedup(?int ...$values): ?int
    {
        $nonNull = [];
        foreach ($values as $value) {
            if ($value !== null) {
                $nonNull[] = $value;
            }
        }

        if ($nonNull === []) {
            return null;
        }

        $unique = array_values(array_unique($nonNull));
        if (count($unique) === 1) {
            return $unique[0];
        }

        return array_sum($nonNull);
    }

    /**
     * @param  list<array{cost:?string, weight:int}>  $pairs
     */
    public function weightedAverageDecimal(array $pairs, int $scale = 4): ?string
    {
        $weightedSum = '0';
        $totalWeight = 0;
        $hasCost = false;

        foreach ($pairs as $pair) {
            $cost = $pair['cost'];
            $weight = max(0, (int) $pair['weight']);
            if ($cost === null || trim($cost) === '' || $weight <= 0) {
                continue;
            }

            $hasCost = true;
            $totalWeight += $weight;
            $weightedSum = $this->addDecimal(
                $weightedSum,
                $this->mulDecimal($cost, (string) $weight, $scale + 2),
            );
        }

        if (! $hasCost || $totalWeight <= 0) {
            return null;
        }

        return $this->divideDecimal($weightedSum, $totalWeight, $scale);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function weightForAverage(array $row): int
    {
        $qty = $row['qty_ordered'] ?? $row['qty_received'] ?? null;

        return max(1, (int) ($qty ?? 1));
    }

    private function weightForItem(PurchaseOrderItem $item): int
    {
        $qty = $item->qty_ordered ?? $item->qty_received ?? null;

        return max(1, (int) ($qty ?? 1));
    }

    private function normalizeSku(string $sku): string
    {
        return trim($sku);
    }

    private function firstNonEmptyString(string ...$values): ?string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function sumNullableDecimals(?string ...$values): ?string
    {
        $sum = '0';
        $hasAny = false;
        foreach ($values as $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }
            $hasAny = true;
            $sum = $this->addDecimal($sum, $value);
        }

        return $hasAny ? $this->formatDecimal($sum, 2) : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function addDecimal(string $a, string $b): string
    {
        if (extension_loaded('bcmath')) {
            /** @var string $out */
            $out = bcadd(trim($a), trim($b), 6);

            return $out;
        }

        return (string) (((float) $a) + ((float) $b));
    }

    private function mulDecimal(string $a, string $b, int $scale): string
    {
        if (extension_loaded('bcmath')) {
            /** @var string $out */
            $out = bcmul(trim($a), trim($b), $scale);

            return $out;
        }

        return (string) (((float) $a) * ((float) $b));
    }

    private function divideDecimal(string $numerator, int $denominator, int $scale): string
    {
        if (extension_loaded('bcmath')) {
            /** @var string $out */
            $out = bcdiv(trim($numerator), (string) max(1, $denominator), $scale);

            return $out;
        }

        return number_format(((float) $numerator) / max(1, $denominator), $scale, '.', '');
    }

    private function formatDecimal(string $value, int $scale): string
    {
        if (extension_loaded('bcmath')) {
            /** @var string $out */
            $out = bcadd(trim($value), '0', $scale);

            return $out;
        }

        return number_format((float) $value, $scale, '.', '');
    }
}
