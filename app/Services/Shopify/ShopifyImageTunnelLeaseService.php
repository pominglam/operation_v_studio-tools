<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ShopifyImageTunnelLeaseService
{
    private const string LOCK_KEY = 'shopify.image_tunnel.lease_lock';

    private const string COUNT_KEY = 'shopify.image_tunnel.lease_count';

    private const string WAS_RUNNING_KEY = 'shopify.image_tunnel.was_running_before_lease';

    public function __construct(
        private readonly CloudflaredTunnel $tunnel,
    ) {}

    /**
     * Ensures the Cloudflare image tunnel is running for the duration of the lease.
     * If the tunnel was stopped before acquire(), it is stopped again on release().
     */
    public function acquire(): ShopifyImageTunnelLease
    {
        try {
            /** @var ShopifyImageTunnelLease $lease */
            $lease = Cache::lock(self::LOCK_KEY, 30)->block(15, function (): ShopifyImageTunnelLease {
                $count = (int) Cache::get(self::COUNT_KEY, 0);
                if ($count === 0) {
                    $status = $this->tunnel->status();
                    $wasRunning = ($status['running'] ?? false) === true;
                    Cache::put(self::WAS_RUNNING_KEY, $wasRunning, now()->addDay());

                    if (! $wasRunning) {
                        $started = $this->tunnel->start();
                        if (! ($started['ok'] ?? false)) {
                            Cache::forget(self::WAS_RUNNING_KEY);

                            $message = is_string($started['error'] ?? null) ? trim($started['error']) : '';
                            throw new \RuntimeException(
                                $message !== '' ? $message : 'Failed to start Cloudflare image tunnel.',
                            );
                        }
                    }
                }

                Cache::put(self::COUNT_KEY, $count + 1, now()->addDay());

                return new ShopifyImageTunnelLease($this, $this->requireTunnelUrl());
            });

            return $lease;
        } catch (LockTimeoutException $e) {
            throw new \RuntimeException('Timed out waiting for Cloudflare image tunnel lease.', 0, $e);
        }
    }

    public function releaseLease(): void
    {
        try {
            Cache::lock(self::LOCK_KEY, 30)->block(15, function (): void {
                $count = max(0, (int) Cache::get(self::COUNT_KEY, 0) - 1);
                if ($count > 0) {
                    Cache::put(self::COUNT_KEY, $count, now()->addDay());

                    return;
                }

                Cache::forget(self::COUNT_KEY);
                $wasRunning = (bool) Cache::get(self::WAS_RUNNING_KEY, true);
                Cache::forget(self::WAS_RUNNING_KEY);

                if (! $wasRunning) {
                    $this->tunnel->stop();
                }
            });
        } catch (LockTimeoutException) {
            // Best-effort restore; do not mask the original push error.
        }
    }

    private function requireTunnelUrl(): string
    {
        $status = $this->tunnel->status();
        $url = is_string($status['tunnel_url'] ?? null) ? trim($status['tunnel_url']) : '';
        if (($status['running'] ?? false) !== true || $url === '') {
            throw new \RuntimeException('Cloudflare image tunnel is running but the public URL is unavailable.');
        }

        return $url;
    }
}
