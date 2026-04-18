<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use Illuminate\Support\Facades\Http;
use Throwable;

final class CloudflareQuickTunnelVerifier
{
    /** @var list<int> */
    private const array OK_STATUSES = [200, 301, 302, 303, 307, 308, 401, 403, 404];

    /**
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

        $probeUrl = "{$tunnelBaseUrl}/";
        $lastStatus = null;

        // Keep this probe quick; it's called from UI refresh.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $res = Http::timeout(3)
                    ->connectTimeout(2)
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

                if ($lastStatus >= 500 && $attempt < 2) {
                    usleep(300_000);

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
                    usleep(300_000);

                    continue;
                }

                return [
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
