<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\DTOs;

final class PriceLookupResult
{
    public function __construct(
        public readonly string $siteKey,
        public readonly string $siteName,
        public readonly string $status, // found | not_found | error
        public readonly ?float $price,
        public readonly ?float $originalPrice,
        public readonly string $currency,
        public readonly ?string $productUrl,
        public readonly ?string $availability, // in_stock | sold_out | null
        public readonly ?string $errorMessage,
    ) {
    }

    public static function found(
        string $siteKey,
        string $siteName,
        float $price,
        ?float $originalPrice,
        string $currency,
        ?string $productUrl,
        ?string $availability,
    ): self
    {
        return new self($siteKey, $siteName, 'found', $price, $originalPrice, $currency, $productUrl, $availability, null);
    }

    public static function notFound(string $siteKey, string $siteName): self
    {
        return new self($siteKey, $siteName, 'not_found', null, null, 'CAD', null, null, null);
    }

    public static function error(string $siteKey, string $siteName, string $message): self
    {
        return new self($siteKey, $siteName, 'error', null, null, 'CAD', null, null, $message);
    }
}


