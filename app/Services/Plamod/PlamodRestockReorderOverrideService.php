<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodRestockReorderOverride;

final class PlamodRestockReorderOverrideService
{
    /**
     * @return array<string, int>
     */
    public function bySku(): array
    {
        $map = [];
        foreach (PlamodRestockReorderOverride::query()->get(['sku', 'reorder_qty']) as $row) {
            $map[(string) $row->sku] = (int) $row->reorder_qty;
        }

        return $map;
    }

    /**
     * @return array{sku: string, reorder_qty: int|null, is_overridden: bool}
     */
    public function upsert(string $sku, ?int $reorderQty): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        if ($reorderQty === null) {
            PlamodRestockReorderOverride::query()->where('sku', '=', $sku)->delete();

            return [
                'sku' => $sku,
                'reorder_qty' => null,
                'is_overridden' => false,
            ];
        }

        if ($reorderQty < 0) {
            throw new \InvalidArgumentException('Reorder qty cannot be negative.');
        }

        $override = PlamodRestockReorderOverride::query()->updateOrCreate(
            ['sku' => $sku],
            ['reorder_qty' => $reorderQty],
        );

        return [
            'sku' => $override->sku,
            'reorder_qty' => (int) $override->reorder_qty,
            'is_overridden' => true,
        ];
    }
}
