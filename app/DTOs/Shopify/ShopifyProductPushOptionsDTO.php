<?php

declare(strict_types=1);

namespace App\DTOs\Shopify;

final readonly class ShopifyProductPushOptionsDTO
{
    public function __construct(
        public bool $info,
        public bool $images,
        public bool $quantities,
        public bool $price,
        public bool $publishStatus,
        public bool $salesChannels,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            info: self::boolValue($payload, 'info'),
            images: self::boolValue($payload, 'images'),
            quantities: self::boolValue($payload, 'quantities'),
            price: self::boolValue($payload, 'price'),
            publishStatus: self::boolValue($payload, 'publish_status'),
            salesChannels: self::boolValue($payload, 'sales_channels'),
        );
    }

    public static function allEnabled(): self
    {
        return new self(
            info: true,
            images: true,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        );
    }

    public function hasAny(): bool
    {
        return $this->info
            || $this->images
            || $this->quantities
            || $this->price
            || $this->publishStatus
            || $this->salesChannels;
    }

    public function requiresInventoryScope(): bool
    {
        return $this->quantities;
    }

    public function requiresPublicationsScope(): bool
    {
        return $this->salesChannels;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'info' => $this->info,
            'images' => $this->images,
            'quantities' => $this->quantities,
            'price' => $this->price,
            'publish_status' => $this->publishStatus,
            'sales_channels' => $this->salesChannels,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function boolValue(array $payload, string $key): bool
    {
        if (! array_key_exists($key, $payload)) {
            return false;
        }

        return filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN);
    }
}
