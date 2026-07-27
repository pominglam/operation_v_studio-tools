<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ShopifyOrderPosUserIdFetcher
{
    public function __construct(
        private readonly ShopifyAdminAccessTokenProviderInterface $tokenProvider,
    ) {}

    public function fetchUserId(string $legacyOrderId): ?int
    {
        $legacyOrderId = trim($legacyOrderId);
        if ($legacyOrderId === '') {
            return null;
        }

        [$domain, $version, $token] = $this->adminConfig();

        $url = sprintf(
            'https://%s/admin/api/%s/orders/%s.json?fields=id,user_id',
            $domain,
            $version,
            $legacyOrderId,
        );

        $response = Http::retry(2, 200, throw: false)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->get($url);

        if (! $response->successful()) {
            Log::channel('shopify')->warning('shopify.staff_order_report.user_id_fetch_failed', [
                'order_id' => $legacyOrderId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $userId = $response->json('order.user_id');
        if ($userId === null || $userId === '') {
            return null;
        }

        return (int) $userId;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function adminConfig(): array
    {
        $domain = config('shopify.store_domain');
        $version = config('shopify.api_version');
        if (! is_string($domain) || trim($domain) === '' || ! is_string($version) || trim($version) === '') {
            throw new ShopifyAdminConfigurationException('Missing Shopify Admin API configuration.');
        }

        $token = trim($this->tokenProvider->currentAccessToken());
        if ($token === '') {
            throw new ShopifyAdminConfigurationException('Missing Shopify Admin access token.');
        }

        return [trim($domain), trim($version), $token];
    }
}
