<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodRestockSkuDecisionStatus;
use App\Models\PlamodRestockSkuDecision;

final class PlamodRestockDecisionService
{
    public function __construct(
        private readonly PlamodRestockPlannedMaintainService $plannedMaintain,
    ) {}

    /**
     * @return array{
     *   sku: string,
     *   status: string,
     *   order_qty: int|null,
     *   planned_maintain_qty: int|null
     * }
     */
    public function upsert(
        string $sku,
        PlamodRestockSkuDecisionStatus $status,
        ?int $orderQty = null,
        ?int $plannedMaintainQty = null,
    ): array {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \InvalidArgumentException('SKU is required.');
        }

        if ($status === PlamodRestockSkuDecisionStatus::Included) {
            if ($orderQty === null || $orderQty < 0) {
                throw new \InvalidArgumentException('Order qty must be zero or greater when including a new SKU.');
            }
            if ($plannedMaintainQty === null || $plannedMaintainQty < 0) {
                throw new \InvalidArgumentException('Planned maintain qty is required when including a new SKU.');
            }
        }

        $decision = PlamodRestockSkuDecision::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'status' => $status,
                'order_qty' => $status === PlamodRestockSkuDecisionStatus::Included ? $orderQty : null,
            ],
        );

        if ($status === PlamodRestockSkuDecisionStatus::Included && $plannedMaintainQty !== null) {
            $this->plannedMaintain->upsert($sku, $plannedMaintainQty);
        } else {
            $this->plannedMaintain->clear($sku);
        }

        return [
            'sku' => $decision->sku,
            'status' => $decision->status->value,
            'order_qty' => $decision->order_qty,
            'planned_maintain_qty' => $this->plannedMaintain->findMaintainQty($sku),
        ];
    }

    public function clear(string $sku): void
    {
        PlamodRestockSkuDecision::query()->where('sku', '=', trim($sku))->delete();
        $this->plannedMaintain->clear($sku);
    }

    /**
     * @param  array<int, string>  $skus
     * @return array{updated: int, results: array<int, array{sku: string, status: string, order_qty: int|null, planned_maintain_qty: int|null}>}
     */
    public function bulkUpsert(
        array $skus,
        PlamodRestockSkuDecisionStatus $status,
        ?int $orderQty = null,
        ?int $plannedMaintainQty = null,
    ): array {
        /** @var array<int, array{sku: string, status: string, order_qty: int|null, planned_maintain_qty: int|null}> $results */
        $results = [];

        foreach (array_values(array_unique(array_filter(array_map('trim', $skus)))) as $sku) {
            if ($sku === '') {
                continue;
            }

            $results[] = $this->upsert($sku, $status, $orderQty, $plannedMaintainQty);
        }

        return [
            'updated' => count($results),
            'results' => $results,
        ];
    }
}
