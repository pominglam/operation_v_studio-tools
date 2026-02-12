<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ExternalAccessService
{
    public function __construct(
        private readonly ExternalAccessSettingsService $settings,
        private readonly ExternalAccessAuthService $auth,
        private readonly AppCloudflaredTunnelService $tunnel,
    ) {}

    /**
     * @return array{
     *   enabled: bool,
     *   password_configured: bool,
     *   tunnel: array{
     *     running:bool,
     *     tunnel_url:string|null,
     *     container_id:string|null,
     *     error:string|null,
     *     reachable:bool|null,
     *     reachable_http_status:int|null,
     *     reachable_checked_at:string|null,
     *     reachable_error:string|null
     *   }
     * }
     */
    public function status(): array
    {
        // Best-effort: if the setting is enabled and the tunnel is stopped, start it.
        // Avoid restarting an already-running tunnel (quick tunnel URLs rotate on restart).
        if ($this->settings->isEnabled() && $this->auth->isPasswordConfigured()) {
            $st = $this->tunnel->status();
            if (! (bool) ($st['running'] ?? false)) {
                $this->tunnel->start();
            }
        }

        return [
            'enabled' => $this->settings->isEnabled(),
            'password_configured' => $this->auth->isPasswordConfigured(),
            'tunnel' => $this->tunnel->status(),
        ];
    }

    /**
     * @return array{enabled: bool, tunnel: array<string, mixed>}
     */
    public function setEnabled(bool $enabled): array
    {
        if ($enabled && ! $this->auth->isPasswordConfigured()) {
            throw new BadRequestHttpException('password_not_configured');
        }

        $this->settings->setEnabled($enabled);

        if ($enabled) {
            $this->tunnel->start();
        } else {
            $this->tunnel->stop();
        }

        return [
            'enabled' => $this->settings->isEnabled(),
            'tunnel' => $this->tunnel->status(),
        ];
    }
}

