<?php

declare(strict_types=1);

use App\Services\Shopify\CloudflaredTunnel;
use App\Services\Shopify\ShopifyImageTunnelLeaseService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::forget('shopify.image_tunnel.lease_count');
    Cache::forget('shopify.image_tunnel.was_running_before_lease');
});

it('starts a stopped tunnel on acquire and stops it again on release', function (): void {
    $started = 0;
    $stopped = 0;

    app()->instance(CloudflaredTunnel::class, new class($started, $stopped) implements CloudflaredTunnel
    {
        public function __construct(
            private int &$started,
            private int &$stopped,
        ) {}

        public function status(): array
        {
            return [
                'running' => $this->started > 0,
                'tunnel_url' => $this->started > 0 ? 'https://lease-test.trycloudflare.com' : null,
                'container_id' => 'cid',
                'error' => null,
            ];
        }

        public function start(): array
        {
            $this->started++;

            return [
                'ok' => true,
                'tunnel_url' => 'https://lease-test.trycloudflare.com',
                'error' => null,
            ];
        }

        public function stop(): array
        {
            $this->stopped++;
            $this->started = 0;

            return ['ok' => true, 'error' => null];
        }
    });

    $service = app()->make(ShopifyImageTunnelLeaseService::class);
    $lease = $service->acquire();

    expect($lease->tunnelUrl)->toBe('https://lease-test.trycloudflare.com');
    expect($started)->toBe(1);
    expect($stopped)->toBe(0);

    $lease->release();

    expect($stopped)->toBe(1);
});

it('leaves an already running tunnel running after release', function (): void {
    $started = 0;
    $stopped = 0;

    app()->instance(CloudflaredTunnel::class, new class($started, $stopped) implements CloudflaredTunnel
    {
        public function __construct(
            private int &$started,
            private int &$stopped,
        ) {}

        public function status(): array
        {
            return [
                'running' => true,
                'tunnel_url' => 'https://already-up.trycloudflare.com',
                'container_id' => 'cid',
                'error' => null,
            ];
        }

        public function start(): array
        {
            $this->started++;

            return [
                'ok' => true,
                'tunnel_url' => 'https://already-up.trycloudflare.com',
                'error' => null,
            ];
        }

        public function stop(): array
        {
            $this->stopped++;

            return ['ok' => true, 'error' => null];
        }
    });

    $service = app()->make(ShopifyImageTunnelLeaseService::class);
    $lease = $service->acquire();
    expect($lease->tunnelUrl)->toBe('https://already-up.trycloudflare.com');
    expect($started)->toBe(0);

    $lease->release();
    expect($stopped)->toBe(0);
});

it('uses reference counting so nested acquires only stop once', function (): void {
    $stopped = 0;

    app()->instance(CloudflaredTunnel::class, new class($stopped) implements CloudflaredTunnel
    {
        private bool $running = false;

        public function __construct(private int &$stopped) {}

        public function status(): array
        {
            return [
                'running' => $this->running,
                'tunnel_url' => $this->running ? 'https://nested.trycloudflare.com' : null,
                'container_id' => 'cid',
                'error' => null,
            ];
        }

        public function start(): array
        {
            $this->running = true;

            return [
                'ok' => true,
                'tunnel_url' => 'https://nested.trycloudflare.com',
                'error' => null,
            ];
        }

        public function stop(): array
        {
            $this->stopped++;
            $this->running = false;

            return ['ok' => true, 'error' => null];
        }
    });

    $service = app()->make(ShopifyImageTunnelLeaseService::class);
    $first = $service->acquire();
    $second = $service->acquire();

    $first->release();
    expect($stopped)->toBe(0);

    $second->release();
    expect($stopped)->toBe(1);
});
