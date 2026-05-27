<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Models\Shopify\ShopifyOauthInstallation;

final class ShopifyOrderAccessScopeGuard
{
    public function assertHistoricalBackfillAllowed(): void
    {
        if ($this->hasReadAllOrdersAccess()) {
            return;
        }

        throw new ShopifyAdminConfigurationException(
            'Historical order backfill requires the read_all_orders Shopify scope. '
            .'Add read_all_orders to SHOPIFY_OAUTH_SCOPES, request access in the Shopify Partner Dashboard if needed, '
            .'then complete OAuth again at /shopify/oauth/install before re-running the backfill.',
        );
    }

    public function hasReadAllOrdersAccess(): bool
    {
        return $this->configuredScopesIncludeReadAllOrders()
            && $this->installationScopesIncludeReadAllOrders();
    }

    public function configuredScopesIncludeReadAllOrders(): bool
    {
        return in_array('read_all_orders', $this->parseScopeList((string) config('shopify.oauth_scopes')), true);
    }

    public function installationScopesIncludeReadAllOrders(): bool
    {
        /** @var ShopifyOauthInstallation|null $installation */
        $installation = ShopifyOauthInstallation::query()->orderByDesc('id')->first();
        if ($installation === null) {
            return false;
        }

        return in_array('read_all_orders', $this->parseScopeList((string) $installation->scopes), true);
    }

    /**
     * @return list<string>
     */
    private function parseScopeList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $scope): string => strtolower(trim($scope)),
            preg_split('/[\s,]+/', $raw) ?: [],
        )));
    }
}
