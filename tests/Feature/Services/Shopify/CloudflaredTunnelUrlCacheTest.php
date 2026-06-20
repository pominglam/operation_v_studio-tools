<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

it('ignores cached tunnel URL when container started after cache was written', function (): void {
    $containerId = 'abc123container';
    $disk = Storage::disk('local');
    $disk->makeDirectory('shopify');
    $disk->put('shopify/cloudflared_tunnel_urls.json', json_encode([
        $containerId => [
            'tunnel_url' => 'https://stale-host.trycloudflare.com',
            'cached_at' => '2026-06-18T19:00:00+00:00',
        ],
    ]));

    $service = new class
    {
        public function readCache(string $containerId, ?int $startedAtUnix): ?string
        {
            $reflection = new ReflectionClass(App\Services\Shopify\CloudflaredTunnelService::class);
            $method = $reflection->getMethod('cachedTunnelUrl');
            $method->setAccessible(true);
            $instance = $reflection->newInstanceWithoutConstructor();

            return $method->invoke($instance, $containerId, $startedAtUnix);
        }
    };

    $startedAtUnix = strtotime('2026-06-18T20:45:45+00:00');
    expect($service->readCache($containerId, $startedAtUnix))->toBeNull();
});

it('returns cached tunnel URL when container has not restarted since cache write', function (): void {
    $containerId = 'abc123container';
    $disk = Storage::disk('local');
    $disk->makeDirectory('shopify');
    $disk->put('shopify/cloudflared_tunnel_urls.json', json_encode([
        $containerId => [
            'tunnel_url' => 'https://fresh-host.trycloudflare.com',
            'cached_at' => '2026-06-18T20:45:45+00:00',
        ],
    ]));

    $service = new class
    {
        public function readCache(string $containerId, ?int $startedAtUnix): ?string
        {
            $reflection = new ReflectionClass(App\Services\Shopify\CloudflaredTunnelService::class);
            $method = $reflection->getMethod('cachedTunnelUrl');
            $method->setAccessible(true);
            $instance = $reflection->newInstanceWithoutConstructor();

            return $method->invoke($instance, $containerId, $startedAtUnix);
        }
    };

    $startedAtUnix = strtotime('2026-06-18T20:45:45+00:00');
    expect($service->readCache($containerId, $startedAtUnix))
        ->toBe('https://fresh-host.trycloudflare.com');
});
