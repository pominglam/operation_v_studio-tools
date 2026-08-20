<?php

declare(strict_types=1);

namespace App\DTOs\ShipmentTracking;

final readonly class TrackingProbeResultDTO
{
    private function __construct(
        public string $status,
        public ?string $provider = null,
        public ?string $trackingUrl = null,
        public ?string $errorMessage = null,
    ) {}

    public static function resolved(string $provider, string $trackingUrl): self
    {
        return new self('resolved', $provider, $trackingUrl);
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function failed(string $errorMessage): self
    {
        return new self('failed', errorMessage: $errorMessage);
    }
}
