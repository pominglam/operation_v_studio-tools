<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;
use Throwable;

final class CloudflareQuickTunnelVerifier
{
    /** @var list<int> */
    private const array OK_STATUSES = [200, 301, 302, 303, 307, 308, 401, 403, 404];

    /**
     * Verify that the trycloudflare URL is reachable and correctly routing to our images-only service.
     *
     * We request a path-signed URL for a non-existent asset ID, because:
     * - Shopify can strip query parameters from CSV Image Src URLs; path-signed is robust
     * - a 404 still proves the tunnel is routing to our app (asset missing), and signature validation passed
     * - avoids leaking a real image URL for verification
     *
     * @return array{reachable:bool|null, http_status:int|null, checked_at:string, error:string|null}
     */
    public function verify(string $tunnelBaseUrl): array
    {
        $tunnelBaseUrl = rtrim(trim($tunnelBaseUrl), '/');
        $checkedAt = now()->toISOString();

        if ($tunnelBaseUrl === '') {
            return [
                'reachable' => null,
                'http_status' => null,
                'checked_at' => $checkedAt,
                'error' => 'Missing tunnel URL.',
            ];
        }

        $lastStatus = null;
        $expires = now()->addMinutes(5)->getTimestamp();
        $signature = app(ShopifyImageUrlSigner::class)->sign(0, $expires)['signature'];
        $probeUrl = "{$tunnelBaseUrl}/shopify-images/0/{$expires}/{$signature}";

        // trycloudflare can take a moment to be reachable; retry a couple times for transient 5xx.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $res = Http::timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders([
                        'User-Agent' => 'OperationVPricingTool/1.0',
                    ])
                    ->head($probeUrl);

                $lastStatus = $res->status();

                if (in_array($lastStatus, self::OK_STATUSES, true)) {
                    return [
                        'reachable' => true,
                        'http_status' => $lastStatus,
                        'checked_at' => $checkedAt,
                        'error' => null,
                    ];
                }

                // Treat 5xx (e.g. 530) as not-yet-ready; retry shortly.
                if ($lastStatus >= 500 && $attempt < 2) {
                    usleep(650_000);

                    continue;
                }

                return [
                    'reachable' => false,
                    'http_status' => $lastStatus,
                    'checked_at' => $checkedAt,
                    'error' => null,
                ];
            } catch (Throwable $e) {
                if ($attempt < 2) {
                    usleep(650_000);

                    continue;
                }

                return [
                    // Timeouts and intermittent network failures are common with trycloudflare.
                    // Treat as "unknown" so the UI doesn't mislead the user.
                    'reachable' => null,
                    'http_status' => null,
                    'checked_at' => $checkedAt,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'reachable' => false,
            'http_status' => $lastStatus,
            'checked_at' => $checkedAt,
            'error' => null,
        ];
    }
}
