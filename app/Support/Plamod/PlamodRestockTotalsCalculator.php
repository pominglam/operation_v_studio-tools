<?php

declare(strict_types=1);

namespace App\Support\Plamod;

final class PlamodRestockTotalsCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $newProducts
     * @return array{
     *   unique_products: int,
     *   units: int,
     *   product: string,
     *   shipping: string,
     *   landed: string,
     *   lines_with_missing_price: int,
     *   existing: array{unique_products: int, units: int, product: string, shipping: string, landed: string, lines_with_missing_price: int},
     *   new_products: array{unique_products: int, units: int, product: string, shipping: string, landed: string, lines_with_missing_price: int}
     * }
     */
    public static function compute(array $existing, array $newProducts, float $shippingPercent): array
    {
        $existingTotals = self::breakdown($existing, 'proposed_qty', $shippingPercent);
        $includedNew = array_values(array_filter(
            $newProducts,
            static fn (array $line): bool => ($line['status'] ?? '') === 'included',
        ));
        $newTotals = self::breakdown($includedNew, 'order_qty', $shippingPercent);

        return [
            'unique_products' => $existingTotals['unique_products'] + $newTotals['unique_products'],
            'units' => $existingTotals['units'] + $newTotals['units'],
            'product' => self::sumMoney($existingTotals['product'], $newTotals['product']),
            'shipping' => self::sumMoney($existingTotals['shipping'], $newTotals['shipping']),
            'landed' => self::sumMoney($existingTotals['landed'], $newTotals['landed']),
            'lines_with_missing_price' => $existingTotals['lines_with_missing_price']
                + $newTotals['lines_with_missing_price'],
            'existing' => $existingTotals,
            'new_products' => $newTotals,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{unique_products: int, units: int, product: string, shipping: string, landed: string, lines_with_missing_price: int}
     */
    private static function breakdown(array $lines, string $quantityKey, float $shippingPercent): array
    {
        $units = 0;
        $productTotal = 0.0;
        $missingPriceLines = 0;
        $uniqueSkus = [];

        foreach ($lines as $index => $line) {
            $qty = (int) ($line[$quantityKey] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $sku = trim((string) ($line['sku'] ?? ''));
            $uniqueSkus[$sku !== '' ? $sku : "__line_{$index}"] = true;
            $units += $qty;
            $cost = is_array($line['new_landed_cost'] ?? null) ? $line['new_landed_cost'] : null;
            if ($cost === null) {
                $missingPriceLines++;

                continue;
            }

            $productTotal += $qty * (float) $cost['product'];
        }

        $shippingTotal = round($productTotal * ($shippingPercent / 100), 2);

        return [
            'unique_products' => count($uniqueSkus),
            'units' => $units,
            'product' => number_format($productTotal, 2, '.', ''),
            'shipping' => number_format($shippingTotal, 2, '.', ''),
            'landed' => number_format($productTotal + $shippingTotal, 2, '.', ''),
            'lines_with_missing_price' => $missingPriceLines,
        ];
    }

    private static function sumMoney(string $first, string $second): string
    {
        return number_format((float) $first + (float) $second, 2, '.', '');
    }
}
