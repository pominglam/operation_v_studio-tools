<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ShopifyOAuthTokenExchangeService
{
    /**
     * @return array{access_token: string, scope: string|null}
     */
    public function exchangeCodeForOfflineToken(string $shopDomain, string $code): array
    {
        $shopDomain = ShopifyShopDomainNormalizer::normalize($shopDomain);
        if ($shopDomain === '' || ! ShopifyShopDomainNormalizer::isValidShopifyHost($shopDomain)) {
            throw new ShopifyAdminConfigurationException('Invalid Shopify shop domain for token exchange.');
        }

        $clientId = trim((string) config('shopify.client_id'));
        $clientSecret = trim((string) config('shopify.client_secret'));
        if ($clientId === '' || $clientSecret === '') {
            throw new ShopifyAdminConfigurationException('Missing SHOPIFY_CLIENT_ID or SHOPIFY_CLIENT_SECRET configuration.');
        }

        $code = trim($code);
        if ($code === '') {
            throw new ShopifyAdminConfigurationException('Missing OAuth authorization code.');
        }

        $url = sprintf('https://%s/admin/oauth/access_token', $shopDomain);

        $timeout = max(10, (int) config('shopify.graphql_timeout_seconds'));
        $attempts = max(1, (int) config('shopify.graphql_retry_attempts'));
        $sleepMs = max(0, (int) config('shopify.graphql_retry_sleep_ms'));

        /** @var \Illuminate\Http\Client\Response $resp */
        $resp = Http::retry($attempts, $sleepMs, throw: false)
            ->acceptJson()
            ->asForm()
            ->timeout($timeout)
            ->post($url, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
            ]);

        if (! $resp->successful()) {
            Log::channel('shopify')->warning('shopify.oauth.access_token_failed', [
                'status' => $resp->status(),
                'snippet' => mb_substr($resp->body(), 0, 400),
            ]);
            throw new ShopifyAdminConfigurationException('Shopify OAuth token exchange failed (HTTP).');
        }

        /** @var array<string, mixed>|null $json */
        $json = $resp->json();
        if (! is_array($json) || ! isset($json['access_token']) || ! is_string($json['access_token']) || trim($json['access_token']) === '') {
            throw new ShopifyAdminConfigurationException('Shopify OAuth token exchange returned an invalid payload.');
        }

        $scope = array_key_exists('scope', $json) && is_string($json['scope']) ? $json['scope'] : null;

        return [
            'access_token' => trim($json['access_token']),
            'scope' => $scope,
        ];
    }
}
