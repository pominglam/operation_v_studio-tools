<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifyOauthInstallation;
use App\Services\Shopify\Admin\Auth\ShopifyOAuthCallbackProcessor;
use App\Services\Shopify\Admin\Auth\ShopifyOAuthQueryHmacVerifier;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('exchanges oauth code, persists token, then admin graphql uses it', function (): void {
    config([
        'app.url' => 'https://pricing-tool.example',
        'shopify.store_domain' => 'unit.myshopify.com',
        'shopify.client_id' => 'client',
        'shopify.client_secret' => 'sek',
        'shopify.api_version' => '2025-10',
        'shopify.oauth_redirect_uri' => 'https://pricing-tool.example/shopify/oauth/callback',
    ]);

    Http::fake([
        'https://unit.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'oauth_offline_plain',
            'scope' => 'read_products',
        ], 200),
        'https://unit.myshopify.com/admin/api/2025-10/graphql.json' => Http::response([
            'data' => ['locations' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => []]],
        ], 200),
    ]);

    $state = bin2hex(random_bytes(16));

    $qs = [
        'code' => 'abc123code',
        'shop' => 'unit.myshopify.com',
        'state' => $state,
        'timestamp' => (string) time(),
    ];

    $normalizedForSign = ShopifyOAuthQueryHmacVerifier::normalizeParamsForSigning($qs);
    $msg = ShopifyOAuthQueryHmacVerifier::buildMessageSorted($normalizedForSign);
    $qs['hmac'] = hash_hmac('sha256', $msg, 'sek', false);

    $res = $this->withSession([ShopifyOAuthCallbackProcessor::SESSION_STATE_KEY => $state])
        ->get(route('shopify.oauth.callback', $qs));

    $res->assertRedirect('/');

    $row = ShopifyOauthInstallation::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->shop_domain)->toBe('unit.myshopify.com');

    expect(app(ShopifyAdminAccessTokenProviderInterface::class)->currentAccessToken())->toBe('oauth_offline_plain');

    $client = app(ShopifyAdminGraphQlClientInterface::class);
    $decoded = $client->query(ShopifyAdminGraphQlQueries::LOCATIONS_PAGE, [
        'first' => 10,
        'after' => null,
    ]);

    expect($decoded['data']['locations']['nodes'])->toBe([]);
});
