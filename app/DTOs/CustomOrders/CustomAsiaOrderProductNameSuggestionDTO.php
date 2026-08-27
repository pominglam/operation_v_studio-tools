<?php

declare(strict_types=1);

namespace App\DTOs\CustomOrders;

final readonly class CustomAsiaOrderProductNameSuggestionDTO
{
    public function __construct(
        public string $title,
        public ?string $priceCad,
        public string $sourceKey,
        public string $sourceName,
        public ?string $productUrl,
        public ?string $availability,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'price_cad' => $this->priceCad,
            'source_key' => $this->sourceKey,
            'source_name' => $this->sourceName,
            'product_url' => $this->productUrl,
            'availability' => $this->availability,
        ];
    }
}
