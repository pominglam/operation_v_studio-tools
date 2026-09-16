<?php

declare(strict_types=1);

namespace App\DTOs\StorePreorders;

final readonly class StorePreorderListingPreview
{
    /**
     * @param  list<array{id: string, preview_url: string}>  $images
     */
    public function __construct(
        public string $host,
        public string $crawler,
        public string $title,
        public string $suggestedSku,
        public ?string $descriptionHtml,
        public ?string $etaDate,
        public ?string $retailPriceCad,
        public ?string $retailPriceNote,
        public array $images,
        public string $sourceUrl,
    ) {}
}
