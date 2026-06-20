<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ShopifyPushImageSourceVerifier
{
    private const int HTTP_TIMEOUT_SECONDS = 15;

    private const int HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * @param  array<int, array{originalSource?: mixed}>  $files
     */
    public function assertReachable(array $files): void
    {
        foreach ($files as $index => $file) {
            $url = is_string($file['originalSource'] ?? null) ? trim($file['originalSource']) : '';
            if ($url === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Image push file #%d is missing originalSource URL.',
                    $index + 1,
                ));
            }

            $startedAt = microtime(true);
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->get($url);

            Log::channel('shopify')->info('shopify.write.image_source.verify', [
                'url' => $this->redactSignedUrl($url),
                'status' => $response->status(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            if (! $response->successful()) {
                throw new \InvalidArgumentException(sprintf(
                    'Image URL not reachable before Shopify push (HTTP %d): %s',
                    $response->status(),
                    $this->redactSignedUrl($url),
                ));
            }
        }
    }

    private function redactSignedUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $query = is_string($parts['query'] ?? null) ? $parts['query'] : '';
        if ($query === '') {
            return $url;
        }

        parse_str($query, $params);
        foreach (['signature', 'expires'] as $key) {
            if (array_key_exists($key, $params)) {
                $params[$key] = '[redacted]';
            }
        }

        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';

        return $scheme.'://'.$host.$path.'?'.http_build_query($params);
    }
}
