<?php

declare(strict_types=1);

namespace App\Contracts\Shopify;

interface ShopifyAdminAccessTokenProviderInterface
{
    /**
     * Plaintext Admin API access token (typically an offline token from OAuth).
     *
     * @throws \App\Exceptions\Shopify\ShopifyAdminConfigurationException
     */
    public function currentAccessToken(): string;
}
