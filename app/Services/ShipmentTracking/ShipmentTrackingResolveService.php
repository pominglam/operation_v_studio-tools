<?php

declare(strict_types=1);

namespace App\Services\ShipmentTracking;

use App\Contracts\ShipmentTracking\TrackingBrowser;
use App\DAL\ShipmentTracking\ShipmentTrackingResolutionRepository;
use App\DTOs\ShipmentTracking\TrackingProbeResultDTO;
use App\Models\ShipmentTrackingResolution;
use Throwable;

final class ShipmentTrackingResolveService
{
    private const array ALLOWED_HOSTS = [
        '17track.net',
        'www.17track.net',
        't.17track.net',
        'aftership.com',
        'www.aftership.com',
        'kuaidi100.com',
        'www.kuaidi100.com',
        'parcelsapp.com',
        'www.parcelsapp.com',
        'ship24.com',
        'www.ship24.com',
    ];

    public function __construct(
        private readonly ShipmentTrackingResolutionRepository $resolutions,
        private readonly TrackingBrowser $browser,
    ) {}

    public function resolve(int $resolutionId): void
    {
        $resolution = $this->resolutions->findByIdOrFail($resolutionId);
        if ($resolution->status === 'resolved') {
            return;
        }

        $this->markResolving($resolution);

        try {
            $result = $this->browser->resolve($resolution->tracking_number);
            $this->applyResult($resolution, $result);
        } catch (Throwable $exception) {
            $this->markFailed($resolution, $exception->getMessage());
        }
    }

    private function markResolving(ShipmentTrackingResolution $resolution): void
    {
        $resolution->status = 'resolving';
        $resolution->attempt_count++;
        $resolution->last_attempted_at = now();
        $resolution->error_summary = null;
        $this->resolutions->save($resolution);
    }

    private function applyResult(
        ShipmentTrackingResolution $resolution,
        TrackingProbeResultDTO $result,
    ): void {
        if ($result->status === 'resolved' && $this->isAllowedUrl($result->trackingUrl)) {
            $resolution->status = 'resolved';
            $resolution->provider = $result->provider;
            $resolution->tracking_url = $result->trackingUrl;
            $resolution->resolved_at = now();
            $resolution->retry_after = null;
            $resolution->error_summary = null;
            $this->resolutions->save($resolution);

            return;
        }

        if ($result->status === 'not_found') {
            $resolution->status = 'not_found';
            $resolution->retry_after = now()->addDay();
            $resolution->error_summary = null;
            $this->clearLink($resolution);
            $this->resolutions->save($resolution);

            return;
        }

        $this->markFailed($resolution, $result->errorMessage ?? 'Tracking worker failed.');
    }

    private function markFailed(ShipmentTrackingResolution $resolution, string $message): void
    {
        $resolution->status = 'failed';
        $resolution->retry_after = now()->addMinutes(15);
        $resolution->error_summary = mb_substr(trim($message), 0, 500);
        $this->clearLink($resolution);
        $this->resolutions->save($resolution);
    }

    private function clearLink(ShipmentTrackingResolution $resolution): void
    {
        $resolution->provider = null;
        $resolution->tracking_url = null;
        $resolution->resolved_at = null;
    }

    private function isAllowedUrl(?string $url): bool
    {
        if ($url === null || ! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::ALLOWED_HOSTS, true);
    }
}
