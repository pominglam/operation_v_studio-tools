<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ExternalHtmlClient
{
    /**
     * Parallel suggest fetches with per-site rate limits (no cross-source blocking).
     *
     * @param  array<string, array{url: string, site_key: string, headers?: array<string, string>}>  $requests
     * @return array<string, Response|null>
     */
    public function poolGetForSuggest(array $requests): array
    {
        /** @var array<string, Response|null> $results */
        $results = array_fill_keys(array_keys($requests), null);

        /** @var array<string, array{url: string, site_key: string, headers?: array<string, string>}> $pending */
        $pending = [];

        foreach ($requests as $alias => $request) {
            $siteKey = $request['site_key'];
            $url = $request['url'];

            if ($this->tryAcquireSiteRateLimit($url, $siteKey, ':suggest')) {
                $pending[$alias] = $request;

                continue;
            }

            Log::channel('external_api')->warning('external_rate_limit_skipped', [
                'alias' => $alias,
                'site_key' => $siteKey,
                'url' => $url,
                'rate_limit_key' => $this->siteRateLimitKey($siteKey, $url, ':suggest'),
                'updated_at' => now()->toISOString(),
            ]);
        }

        if ($pending === []) {
            return $results;
        }

        $baseHeaders = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-CA,en;q=0.9',
        ];

        try {
            /** @var array<string, Response> $responses */
            $responses = Http::connectTimeout(2)
                ->timeout(8)
                ->pool(function (\Illuminate\Http\Client\Pool $pool) use ($pending, $baseHeaders): void {
                    foreach ($pending as $alias => $request) {
                        $headers = [...$baseHeaders, ...($request['headers'] ?? [])];
                        $pool->as($alias)
                            ->withHeaders($headers)
                            ->get($request['url']);
                    }
                });
        } catch (Throwable $e) {
            Log::channel('external_api')->error('external_pool_error', [
                'aliases' => array_keys($pending),
                'error' => $e->getMessage(),
                'updated_at' => now()->toISOString(),
            ]);

            return $results;
        }

        foreach ($responses as $alias => $response) {
            if (! isset($pending[$alias])) {
                continue;
            }

            $request = $pending[$alias];
            $traceId = (string) Str::uuid();

            Log::channel('external_api')->info('external_response', [
                'trace_id' => $traceId,
                'method' => 'GET',
                'url' => $request['url'],
                'site_key' => $request['site_key'],
                'status' => $response->status(),
                'pool' => 'suggest',
                'updated_at' => now()->toISOString(),
            ]);

            $results[$alias] = $response;
        }

        return $results;
    }

    /**
     * Fast JSON suggest/autocomplete fetch — shorter timeout and separate rate-limit bucket.
     *
     * @param  array<string, string>  $headers
     */
    public function getForSuggest(string $url, array $headers = [], ?string $siteKey = null): Response
    {
        return $this->get(
            url: $url,
            headers: $headers,
            siteKey: $siteKey,
            timeoutSeconds: 8,
            rateLimitKeySuffix: ':suggest',
            maxAttempts: 2,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function get(
        string $url,
        array $headers = [],
        ?string $siteKey = null,
        ?int $timeoutSeconds = null,
        ?string $rateLimitKeySuffix = null,
        ?int $maxAttempts = null,
    ): Response {
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
            $this->throttle($url, $siteKey, $traceId, $rateLimitKeySuffix);

            $baseHeaders = [
                // Use a browser-like UA to reduce WAF/bot-protection false positives.
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-CA,en;q=0.9',
            ];

            $response = $this->sendWithRetry(
                url: $url,
                headers: [...$baseHeaders, ...$headers],
                siteKey: $siteKey,
                traceId: $traceId,
                timeoutSeconds: $timeoutSeconds ?? 20,
                maxAttempts: $maxAttempts ?? 5,
            );

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

    /**
     * Respect remote rate-limits (429/503) by waiting and retrying.
     *
     * @param  array<string, string>  $headers
     */
    private function sendWithRetry(
        string $url,
        array $headers,
        ?string $siteKey,
        string $traceId,
        int $timeoutSeconds = 20,
        int $maxAttempts = 5,
    ): Response {
        $attempt = 0;
        $sleepSeconds = 0;

        while (true) {
            $attempt++;
            if ($sleepSeconds > 0) {
                Log::channel('external_api')->warning('external_retry_sleep', [
                    'trace_id' => $traceId,
                    'url' => $url,
                    'site_key' => $siteKey,
                    'attempt' => $attempt,
                    'sleep_seconds' => $sleepSeconds,
                    'updated_at' => now()->toISOString(),
                ]);
                sleep($sleepSeconds);
            }

            $response = Http::connectTimeout(3)
                ->timeout($timeoutSeconds)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 10,
                        'strict' => true,
                    ],
                ])
                ->withHeaders($headers)
                ->get($url);

            $status = $response->status();
            if (! in_array($status, [429, 503, 520, 521, 522, 524], true)) {
                return $response;
            }

            if ($attempt >= $maxAttempts) {
                return $response;
            }

            $sleepSeconds = $this->sleepSecondsForRetry($response, $attempt);
        }
    }

    private function sleepSecondsForRetry(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');
        if (is_string($retryAfter) && trim($retryAfter) !== '' && ctype_digit(trim($retryAfter))) {
            return max(1, min((int) trim($retryAfter), 60));
        }

        // Exponential backoff, capped (attempt starts at 1).
        $base = 2 ** max(0, $attempt - 1);

        return max(1, min((int) $base, 30));
    }

    private function throttle(string $url, ?string $siteKey, string $traceId, ?string $rateLimitKeySuffix = null): void
    {
        if ($this->tryAcquireSiteRateLimit($url, $siteKey, $rateLimitKeySuffix)) {
            return;
        }

        $key = $this->siteRateLimitKey($siteKey, $url, $rateLimitKeySuffix);
        $perMinute = $this->siteRateLimitPerMinute($siteKey, $rateLimitKeySuffix === ':suggest');
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

        sleep($waitSeconds);
        RateLimiter::hit($key, 60);
    }

    private function tryAcquireSiteRateLimit(string $url, ?string $siteKey, ?string $rateLimitKeySuffix = null): bool
    {
        $key = $this->siteRateLimitKey($siteKey, $url, $rateLimitKeySuffix);
        $perMinute = $this->siteRateLimitPerMinute($siteKey, $rateLimitKeySuffix === ':suggest');

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    private function siteRateLimitKey(?string $siteKey, string $url, ?string $rateLimitKeySuffix = null): string
    {
        $suffix = $rateLimitKeySuffix ?? '';

        if ($siteKey !== null && $siteKey !== '') {
            return "price_research:site:{$siteKey}{$suffix}";
        }

        return $this->hostKeyForUrl($url).$suffix;
    }

    private function siteRateLimitPerMinute(?string $siteKey, bool $isSuggest): int
    {
        $defaultPerMinute = $isSuggest ? 30 : $this->globalHitsPerMinute();
        $override = null;
        if ($siteKey !== null && $siteKey !== '') {
            $v = config('price_research.rate_limit.per_site_overrides.'.$siteKey);
            if (is_numeric($v)) {
                $override = (int) $v;
            }
        }
        $perMinute = max(1, $override ?? $defaultPerMinute);
        if ($isSuggest) {
            $perMinute = max($perMinute, 30);
        }

        return $perMinute;
    }

    private function globalHitsPerMinute(): int
    {
        /** @var int $cached */
        $cached = Cache::remember('settings:external_hits_per_minute', 60, static function (): int {
            if (! Schema::hasTable('maintenance_notes')) {
                return max(1, (int) config('price_research.rate_limit.per_site_per_minute', 10));
            }

            $raw = \Illuminate\Support\Facades\DB::table('maintenance_notes')
                ->where('key', '=', \App\Services\Maintenance\ExternalRateLimitService::KEY)
                ->value('body');

            $raw = is_string($raw) ? trim($raw) : '';
            if ($raw === '' || ! ctype_digit($raw)) {
                return max(1, (int) config('price_research.rate_limit.per_site_per_minute', 10));
            }

            return max(1, min((int) $raw, 120));
        });

        return max(1, (int) $cached);
    }

    private function hostKeyForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? $host : 'unknown';

        return "price_research:host:{$host}";
    }
}
