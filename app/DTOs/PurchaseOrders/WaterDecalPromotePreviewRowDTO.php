<?php

declare(strict_types=1);

namespace App\DTOs\PurchaseOrders;

final readonly class WaterDecalPromotePreviewRowDTO
{
    /**
     * @param  array{
     *   product_id: int,
     *   sku: string,
     *   description: string|null,
     *   handle: string|null,
     *   selling_price: string|null
     * }|null  $mergeTarget
     */
    public function __construct(
        public int $itemId,
        public string $intention,
        public string $intentionLabel,
        public string $currentSku,
        public string $currentDescription,
        public ?string $currentMainType,
        public string $proposedSku,
        public string $proposedDescription,
        public string $proposedVendor,
        public string $proposedType,
        public ?array $mergeTarget,
        public ?string $warning,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'intention' => $this->intention,
            'intention_label' => $this->intentionLabel,
            'current_sku' => $this->currentSku,
            'current_description' => $this->currentDescription,
            'current_main_type' => $this->currentMainType,
            'proposed_sku' => $this->proposedSku,
            'proposed_description' => $this->proposedDescription,
            'proposed_vendor' => $this->proposedVendor,
            'proposed_type' => $this->proposedType,
            'merge_target' => $this->mergeTarget,
            'warning' => $this->warning,
        ];
    }
}
