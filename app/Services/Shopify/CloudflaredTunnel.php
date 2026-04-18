<?php

declare(strict_types=1);

namespace App\Services\Shopify;

interface CloudflaredTunnel
{
    /**
     * @return array{running:bool, tunnel_url:string|null, container_id:string|null, error:string|null}
     */
    public function status(): array;

    /**
     * @return array{ok:bool, tunnel_url:string|null, error:string|null}
     */
    public function start(): array;

    /**
     * @return array{ok:bool, error:string|null}
     */
    public function stop(): array;
}
