<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodRestockPlannedMaintain;
use App\Models\Product;

final class PlamodRestockPlannedMaintainService
{
    public function upsert(string $sku, int $maintainQty): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        if ($maintainQty < 0) {
            throw new \InvalidArgumentException('Maintain qty must be zero or greater.');
        }

        PlamodRestockPlannedMaintain::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'maintain_qty' => $maintainQty,
                'applied_at' => null,
            ],
        );
    }

    public function clear(string $sku): void
    {
        PlamodRestockPlannedMaintain::query()->where('sku', '=', trim($sku))->delete();
    }

    public function findMaintainQty(string $sku): ?int
    {
        /** @var PlamodRestockPlannedMaintain|null $row */
        $row = PlamodRestockPlannedMaintain::query()
            ->where('sku', '=', trim($sku))
            ->whereNull('applied_at')
            ->first();

        return $row?->maintain_qty;
    }

    /**
     * @return array<string, int>
     */
    public function pendingBySku(): array
    {
        $out = [];
        foreach (PlamodRestockPlannedMaintain::query()->whereNull('applied_at')->get() as $row) {
            $out[$row->sku] = (int) $row->maintain_qty;
        }

        return $out;
    }

    public function pendingMaintainForSku(string $sku): ?int
    {
        return $this->findMaintainQty($sku);
    }

    public function markApplied(string $sku): void
    {
        PlamodRestockPlannedMaintain::query()
            ->where('sku', '=', trim($sku))
            ->whereNull('applied_at')
            ->update(['applied_at' => now()]);
    }

    public function applyToNewProduct(Product $product): bool
    {
        if (strcasecmp(trim((string) $product->vendor), 'Plamod') !== 0) {
            return false;
        }

        $maintainQty = $this->pendingMaintainForSku((string) $product->sku);
        if ($maintainQty === null) {
            return false;
        }

        $product->maintain_qty = $maintainQty;

        return true;
    }
}
