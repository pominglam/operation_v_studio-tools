<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

final readonly class StorePreorderListingCrawlResult
{
    /**
     * @param  list<string>  $imageUrls
     */
    public function __construct(
        public string $title,
        public ?string $descriptionHtml,
        public ?string $etaDate,
        public ?string $retailPriceCad,
        public array $imageUrls,
        public string $sourceUrl,
    ) {}
}
