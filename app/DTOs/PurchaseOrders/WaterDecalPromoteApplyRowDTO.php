<?php

declare(strict_types=1);

namespace App\DTOs\PurchaseOrders;

final readonly class WaterDecalPromoteApplyRowDTO
{
    public function __construct(
        public int $itemId,
        public string $sku,
        public string $description,
        public string $vendor,
        public string $type,
        public bool $confirmMerge,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromValidated(array $row): self
    {
        return new self(
            itemId: (int) $row['item_id'],
            sku: trim((string) $row['sku']),
            description: trim((string) $row['description']),
            vendor: trim((string) $row['vendor']),
            type: trim((string) ($row['type'] ?? 'Others')),
            confirmMerge: (bool) ($row['confirm_merge'] ?? false),
        );
    }
}
