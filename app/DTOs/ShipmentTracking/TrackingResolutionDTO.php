<?php

declare(strict_types=1);

namespace App\DTOs\ShipmentTracking;

use App\Models\ShipmentTrackingResolution;

final readonly class TrackingResolutionDTO
{
    public function __construct(
        public string $trackingNumber,
        public string $status,
        public ?string $provider,
        public ?string $trackingUrl,
        public ?string $retryAfter,
    ) {}

    public static function fromModel(
        string $requestedTrackingNumber,
        ShipmentTrackingResolution $resolution,
    ): self {
        return new self(
            trackingNumber: $requestedTrackingNumber,
            status: $resolution->status,
            provider: $resolution->provider,
            trackingUrl: $resolution->tracking_url,
            retryAfter: $resolution->retry_after?->toISOString(),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'tracking_number' => $this->trackingNumber,
            'status' => $this->status,
            'provider' => $this->provider,
            'tracking_url' => $this->trackingUrl,
            'retry_after' => $this->retryAfter,
        ];
    }
}
