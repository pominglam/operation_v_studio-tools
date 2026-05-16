<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin;

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ShopifyAdminGraphQlClient implements ShopifyAdminGraphQlClientInterface
{
    public function __construct(
        private readonly ShopifyAdminAccessTokenProviderInterface $tokenProvider,
    ) {}

    public function query(string $graphql, array $variables = []): array
    {
        [$baseUrl, $token, $timeout, $attempts, $sleepMs] = $this->configTuple();
        /** @var Response $resp */
        $resp = Http::retry($attempts, $sleepMs, throw: false)
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $token,
            ])
            ->post($baseUrl, [
                'query' => $graphql,
                'variables' => $variables !== [] ? $variables : (object) [],
            ]);

        if (! $resp->successful()) {
            Log::channel('shopify')->warning('shopify.admin_graphql.transport', [
                'status' => $resp->status(),
                'snippet' => mb_substr($resp->body(), 0, 500),
            ]);
            throw new ShopifyGraphQlException('Shopify GraphQL transport failed.');
        }

        /** @var array<string, mixed> $json */
        $json = $resp->json();

        if (isset($json['errors']) && is_array($json['errors']) && $json['errors'] !== []) {
            Log::channel('shopify')->warning('shopify.admin_graphql.errors', ['errors' => $json['errors']]);
            throw new ShopifyGraphQlException('Shopify GraphQL returned GraphQL-level errors.');
        }

        return $json;
    }

    /**
     * @return array{0:string,1:string,2:int,3:int,4:int}
     */
    private function configTuple(): array
    {
        /** @var string|null $domain */
        $domain = config('shopify.store_domain');
        /** @var string|null $version */
        $version = config('shopify.api_version');
        if (! is_string($domain) || trim($domain) === '') {
            throw new ShopifyAdminConfigurationException('Missing SHOPIFY_STORE_DOMAIN configuration.');
        }

        try {
            $token = trim($this->tokenProvider->currentAccessToken());
        } catch (ShopifyAdminConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShopifyAdminConfigurationException(
                sprintf('Unable to resolve Shopify Admin access token: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        if ($token === '') {
            throw new ShopifyAdminConfigurationException('Persisted Shopify access token resolves empty.');
        }

        if (! is_string($version) || trim($version) === '') {
            throw new ShopifyAdminConfigurationException('Missing SHOPIFY_API_VERSION configuration.');
        }

        $host = strtolower(trim(str_replace(['https://', 'http://'], '', $domain)));
        $timeout = max(5, (int) config('shopify.graphql_timeout_seconds'));
        $attempts = max(1, (int) config('shopify.graphql_retry_attempts'));
        $sleepMs = max(0, (int) config('shopify.graphql_retry_sleep_ms'));
        $url = sprintf('https://%s/admin/api/%s/graphql.json', $host, trim((string) $version, '/'));

        return [$url, $token, $timeout, $attempts, $sleepMs];
    }
}
