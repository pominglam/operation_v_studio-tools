<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerRetentionOrderRef
{
    public function __construct(
        public int $id,
        public ?string $name,
        public ?string $orderedAtIso,
        public ?string $subtotal,
        public ?string $channelLabel,
        public ?string $shopifyAdminUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ordered_at' => $this->orderedAtIso,
            'subtotal' => $this->subtotal,
            'channel_label' => $this->channelLabel,
            'shopify_admin_url' => $this->shopifyAdminUrl,
        ];
    }
}
