<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class ShopifyOAuthCallbackProcessor
{
    public const string SESSION_STATE_KEY = 'shopify.oauth.state';

    public function __construct(
        private readonly ShopifyOAuthTokenExchangeService $tokenExchangeService,
        private readonly ShopifyOauthInstallationWriter $installationWriter,
    ) {}

    public function processCallback(Request $request): RedirectResponse
    {
        $clientSecret = trim((string) config('shopify.client_secret'));
        if ($clientSecret === '') {
            $this->abortOAuth('Missing SHOPIFY_CLIENT_SECRET.', 'shopify.oauth.callback_missing_secret');
        }

        /** @var array<string, mixed> $raw */
        $raw = $request->query->all();

        foreach ($raw as $value) {
            if (is_array($value)) {
                $this->abortOAuth('Malformed OAuth callback query.', 'shopify.oauth.callback_arrays');
            }
        }

        $hmacHex = isset($raw['hmac']) && is_string($raw['hmac']) ? trim($raw['hmac']) : '';
        $state = isset($raw['state']) && is_string($raw['state']) ? $raw['state'] : '';
        $shop = isset($raw['shop']) && is_string($raw['shop']) ? strtolower(trim($raw['shop'])) : '';
        $timestampCandidate = $raw['timestamp'] ?? null;
        $timestamp = is_numeric($timestampCandidate) ? (int) $timestampCandidate : null;
        $code = isset($raw['code']) && is_string($raw['code']) ? trim($raw['code']) : '';

        if ($timestamp === null || abs(time() - $timestamp) > 600) {
            $this->abortOAuth('Stale or missing OAuth timestamp.', 'shopify.oauth.callback_timestamp');
        }

        if ($shop === '' || ! ShopifyShopDomainNormalizer::isValidShopifyHost($shop)) {
            $this->abortOAuth('Invalid `shop` on OAuth callback.', 'shopify.oauth.callback_shop_invalid');
        }

        if ($code === '') {
            $this->abortOAuth('Missing authorization `code` on OAuth callback.', 'shopify.oauth.callback_missing_code');
        }

        if ($state === '' || strlen($state) < 8) {
            $this->abortOAuth('Missing OAuth `state` parameter.', 'shopify.oauth.callback_missing_state');
        }

        $expectedState = $request->session()->pull(self::SESSION_STATE_KEY);
        if (! is_string($expectedState) || ! hash_equals($expectedState, $state)) {
            $this->abortOAuth('OAuth `state` mismatch (restart install).', 'shopify.oauth.callback_state_mismatch');
        }

        $normalized = ShopifyOAuthQueryHmacVerifier::normalizeParamsForSigning($raw);
        $message = ShopifyOAuthQueryHmacVerifier::buildMessageSorted($normalized);

        if (! ShopifyOAuthQueryHmacVerifier::verify($message, $hmacHex, $clientSecret)) {
            Log::channel('shopify')->warning('shopify.oauth.callback_hmac_bad');
            $this->abortOAuth('Invalid OAuth HMAC.', 'shopify.oauth.callback_hmac');
        }

        $configuredShop = ShopifyShopDomainNormalizer::normalize((string) config('shopify.store_domain'));
        if ($configuredShop === '' || ! ShopifyShopDomainNormalizer::isValidShopifyHost($configuredShop)) {
            $this->abortOAuth(
                'Configure SHOPIFY_STORE_DOMAIN before completing OAuth.',
                'shopify.oauth.callback_store_not_configured',
            );
        }

        if ($shop !== $configuredShop) {
            Log::channel('shopify')->warning('shopify.oauth.callback_shop_mismatch', [
                'configured' => $configuredShop,
                'received' => $shop,
            ]);
            $this->abortOAuth(
                sprintf('OAuth shop `%s` does not equal configured `%s`.', $shop, $configuredShop),
                'shopify.oauth.callback_shop_mismatch_human',
            );
        }

        try {
            $payload = $this->tokenExchangeService->exchangeCodeForOfflineToken($shop, $code);
        } catch (ShopifyAdminConfigurationException $e) {
            $this->abortOAuth($e->getMessage(), 'shopify.oauth.token_exchange_configuration');
        } catch (\Throwable $e) {
            Log::channel('shopify')->error('shopify.oauth.callback_exchange_exception', [
                'exception' => $e->getMessage(),
            ]);
            $this->abortOAuth('Token exchange crashed.', 'shopify.oauth.callback_exchange_exception');
        }

        $this->installationWriter->upsertInstallation(
            shopDomain: $shop,
            accessTokenPlain: $payload['access_token'],
            scopes: $payload['scope'],
        );

        Log::channel('shopify')->info('shopify.oauth.complete', ['shop_domain' => $shop]);

        return redirect()->to('/')->with('shopify_oauth_ok', true);
    }

    private function abortOAuth(string $humanMessage, string $logSlug): never
    {
        Log::channel('shopify')->warning($logSlug, ['message' => $humanMessage]);
        abort(403, $humanMessage);
    }
}
