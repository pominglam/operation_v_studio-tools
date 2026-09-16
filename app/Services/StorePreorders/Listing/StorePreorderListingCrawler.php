<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

interface StorePreorderListingCrawler
{
    /**
     * @return list<string>
     */
    public function hosts(): array;

    public function crawl(string $url): StorePreorderListingCrawlResult;
}
