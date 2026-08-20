<?php

declare(strict_types=1);

namespace App\Services\ShipmentTracking;

use App\Contracts\ShipmentTracking\TrackingBrowser;
use App\DTOs\ShipmentTracking\TrackingProbeResultDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class HttpTrackingBrowserClient implements TrackingBrowser
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    public function resolve(string $trackingNumber): TrackingProbeResultDTO
    {
        $url = rtrim($this->baseUrl, '/').'/resolve';
        $traceId = (string) Str::uuid();
        $startedAt = hrtime(true);

        Log::channel('external_api')->info('Tracking browser request started.', [
            'trace_id' => $traceId,
            'url' => $url,
            'method' => 'POST',
            'tracking_number' => $trackingNumber,
        ]);

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(165)
                ->retry(2, static fn (): int => random_int(250, 750), throw: false)
                ->post($url, ['tracking_number' => $trackingNumber]);
        } catch (Throwable $exception) {
            $this->logFinished($traceId, $url, null, $startedAt, 'error');

            return TrackingProbeResultDTO::failed($exception->getMessage());
        }

        $level = $response->successful() ? 'info' : 'warning';
        $this->logFinished($traceId, $url, $response->status(), $startedAt, $level);
        if (! $response->successful()) {
            return TrackingProbeResultDTO::failed("Tracking worker returned HTTP {$response->status()}.");
        }

        $status = (string) $response->json('status', 'failed');
        if ($status === 'resolved') {
            return TrackingProbeResultDTO::resolved(
                provider: (string) $response->json('provider'),
                trackingUrl: (string) $response->json('tracking_url'),
            );
        }
        if ($status === 'not_found') {
            return TrackingProbeResultDTO::notFound();
        }

        return TrackingProbeResultDTO::failed(
            (string) $response->json('error_message', 'Tracking worker failed.'),
        );
    }

    private function logFinished(
        string $traceId,
        string $url,
        ?int $status,
        int $startedAt,
        string $level,
    ): void {
        Log::channel('external_api')->log($level, 'Tracking browser request finished.', [
            'trace_id' => $traceId,
            'url' => $url,
            'method' => 'POST',
            'status_code' => $status,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
