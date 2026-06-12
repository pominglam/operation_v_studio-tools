<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;

final class PlamodPreorderLiveHitImportService
{
    /**
     * Upsert preorder rows discovered via live Plamod search (PDP-enriched).
     * Fills snapshot gaps until the next full CSV sync overwrites/merges.
     *
     * @param  array<int, array<string, mixed>>  $resourceRows
     */
    public function upsertResourceRows(array $resourceRows): int
    {
        $upserted = 0;
        $now = now();

        foreach ($resourceRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            if (! PlamodPreorderSkuValidator::isValid($sku)) {
                continue;
            }

            $attributes = [
                'barcode' => $this->nullableString($row['barcode'] ?? null),
                'product_name' => trim((string) ($row['product_name'] ?? $sku)) ?: $sku,
                'series' => $this->nullableString($row['series'] ?? null),
                'release_date' => $this->nullableString($row['release_date'] ?? null),
                'manufacturer' => $this->nullableString($row['manufacturer'] ?? null),
                'category' => $this->nullableString($row['category'] ?? null),
                'price_stock' => $this->nullableString($row['price_stock'] ?? null),
                'price_preorder' => $this->nullableString($row['price_preorder'] ?? null),
                'price_backorder' => $this->nullableString($row['price_backorder'] ?? null),
                'quantity_preorder' => $this->nullableInt($row['quantity_preorder'] ?? null),
                'po_due_date' => $this->nullableString($row['po_due_date'] ?? null),
                'eta_date' => $this->nullableString($row['eta_date'] ?? null),
                'source_image_url' => $this->nullableString($row['image_url'] ?? null),
                'dropped_at' => null,
                'last_seen_at' => $now,
            ];

            /** @var PlamodPreorder|null $existing */
            $existing = PlamodPreorder::query()->where('sku', '=', $sku)->first();
            if ($existing !== null) {
                foreach ($attributes as $key => $value) {
                    if ($value === null && $existing->getAttribute($key) !== null) {
                        unset($attributes[$key]);
                    }
                }

                if ($existing->image_storage_path !== null) {
                    unset($attributes['source_image_url']);
                }
            }

            if ($existing === null) {
                $attributes['image_download_status'] = PlamodPreorder::IMAGE_STATUS_PENDING;
            }

            PlamodPreorder::query()->updateOrCreate(['sku' => $sku], $attributes);
            $upserted++;
        }

        return $upserted;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
