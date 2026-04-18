<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\DAL\Inventory\InventoryRepository;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

final class InventoryFifoDeductionService
{
    public function __construct(
        private readonly InventoryRepository $inventory,
    ) {}

    /**
     * Deduct qty from FIFO lots. If lots are insufficient, go negative and return underflow amount.
     *
     * @return array{deducted:int, underflow:int}
     */
    public function deductForInventoryCheck(int $productId, int $qtyToDeduct, string $inventoryCheckUuid): array
    {
        $qtyToDeduct = max(0, $qtyToDeduct);
        if ($qtyToDeduct === 0) {
            return ['deducted' => 0, 'underflow' => 0];
        }

        return DB::transaction(function () use ($productId, $qtyToDeduct, $inventoryCheckUuid): array {
            $remaining = $qtyToDeduct;
            $deducted = 0;

            $lots = $this->inventory->listFifoLotsForProductForUpdate($productId);
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $available = (int) $lot->qty_remaining;
                if ($available <= 0) {
                    continue;
                }

                $consume = min($available, $remaining);
                $lot->qty_remaining = $available - $consume;
                $this->inventory->saveLot($lot);

                $movement = new InventoryMovement;
                $movement->product_id = $productId;
                $movement->inventory_lot_id = (int) $lot->id;
                $movement->kind = 'deduct';
                $movement->qty_delta = -$consume;
                $movement->reference_type = 'inventory_check';
                $movement->reference_uuid = $inventoryCheckUuid;
                $movement->occurred_at = now();
                $this->inventory->createMovement($movement);

                $deducted += $consume;
                $remaining -= $consume;
            }

            $underflow = 0;
            if ($remaining > 0) {
                $underflow = $remaining;

                $neg = $this->inventory->findOrCreateNegativeBalanceLot($productId);
                $neg->qty_remaining = (int) $neg->qty_remaining - $remaining;
                $this->inventory->saveLot($neg);

                $movement = new InventoryMovement;
                $movement->product_id = $productId;
                $movement->inventory_lot_id = (int) $neg->id;
                $movement->kind = 'underflow';
                $movement->qty_delta = -$remaining;
                $movement->reference_type = 'inventory_check';
                $movement->reference_uuid = $inventoryCheckUuid;
                $movement->occurred_at = now();
                $this->inventory->createMovement($movement);
            }

            return ['deducted' => $deducted, 'underflow' => $underflow];
        });
    }
}
