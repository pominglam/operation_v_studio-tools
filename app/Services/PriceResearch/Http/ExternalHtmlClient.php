<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class ExternalHtmlClient
{
    /**
     * @param  array<string, string>  $headers
     */
    public function get(string $url, array $headers = [], ?string $siteKey = null): Response
    {
        $traceId = (string) Str::uuid();
        $startedAt = microtime(true);

        Log::channel('external_api')->info('external_request', [
            'trace_id' => $traceId,
            'method' => 'GET',
            'url' => $url,
            'site_key' => $siteKey,
            'created_at' => now()->toISOString(),
        ]);

        try {
            $this->throttle($url, $siteKey, $traceId);

            $baseHeaders = [
                // Use a browser-like UA to reduce WAF/bot-protection false positives.
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-CA,en;q=0.9',
            ];

            $response = Http::connectTimeout(3)
                ->timeout(20)
                ->retry(1, 200)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 10,
                        'strict' => true,
                    ],
                ])
                ->withHeaders([
                    ...$baseHeaders,
                    ...$headers,
                ])
                ->get($url);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::channel('external_api')->info('external_response', [
                'trace_id' => $traceId,
                'method' => 'GET',
                'url' => $url,
                'site_key' => $siteKey,
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'updated_at' => now()->toISOString(),
            ]);

            return $response;
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::channel('external_api')->error('external_error', [
                'trace_id' => $traceId,
                'method' => 'GET',
                'url' => $url,
                'site_key' => $siteKey,
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
                'updated_at' => now()->toISOString(),
            ]);

            throw $e;
        }
    }

    private function throttle(string $url, ?string $siteKey, string $traceId): void
    {
        $defaultPerMinute = max(1, (int) config('price_research.rate_limit.per_site_per_minute', 10));
        $override = null;
        if ($siteKey !== null && $siteKey !== '') {
            $v = config('price_research.rate_limit.per_site_overrides.'.$siteKey);
            if (is_numeric($v)) {
                $override = (int) $v;
            }
        }
        $perMinute = max(1, $override ?? $defaultPerMinute);
        $decaySeconds = 60;

        $key = $siteKey !== null && $siteKey !== ''
            ? "price_research:site:{$siteKey}"
            : $this->hostKeyForUrl($url);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $waitSeconds = max(1, (int) RateLimiter::availableIn($key));

            Log::channel('external_api')->warning('external_rate_limited', [
                'trace_id' => $traceId,
                'site_key' => $siteKey,
                'url' => $url,
                'rate_limit_key' => $key,
                'per_minute' => $perMinute,
                'sleep_seconds' => $waitSeconds,
                'updated_at' => now()->toISOString(),
            ]);

            // Throttle by waiting until the limiter window resets for this site.
            sleep($waitSeconds);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function hostKeyForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? $host : 'unknown';

        return "price_research:host:{$host}";
    }
}
