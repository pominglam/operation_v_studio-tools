<?php

declare(strict_types=1);

use App\Models\Shopify\ShopifyWebhookLog;
use App\Services\Maintenance\ExternalAccessSettingsService;
use Illuminate\Support\Facades\Config;

it('rejects Shopify webhooks with bad HMAC and logs verification failure', function (): void {
    Config::set('shopify.webhook_secret', 'unit-test-secret');

    $body = '{"id":456}';

    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $response = $this->call(
        'POST',
        '/api/webhooks/shopify',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FORWARDED_HOST' => 'ovs.example.test',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'unit.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-123',
            'HTTP_X_SHOPIFY_REQUEST_ID' => 'req-1',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'bad',
        ],
        $body,
    );

    $response->assertStatus(401)->assertJsonPath('ok', false);

    /** @var ShopifyWebhookLog|null $log */
    $log = ShopifyWebhookLog::query()->first();
    expect($log)->not->toBeNull();
    expect($log->verification_ok)->toBeFalse();
    expect($log->verification_error)->toContain('invalid_hmac');
});

it('accepts valid Shopify webhook HMAC and dispatches ingest flow', function (): void {
    Config::set('shopify.webhook_secret', 'unit-test-secret');
    app(ExternalAccessSettingsService::class)->setEnabled(true);
    Config::set('app.external_access_password', 'gate');

    $body = '{"hello":"world"}';
    $hmac = base64_encode(hash_hmac('sha256', $body, 'unit-test-secret', true));

    $response = $this->call(
        'POST',
        '/api/webhooks/shopify',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FORWARDED_HOST' => 'ovs.example.test',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'unit.myshopify.com',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        ],
        $body,
    );

    $response->assertStatus(200)->assertJsonPath('ok', true);

    /** @var ShopifyWebhookLog|null $log */
    $log = ShopifyWebhookLog::query()->first();
    expect($log)->not->toBeNull();
    expect($log->verification_ok)->toBeTrue();
    expect($log->processing_status)->toBe('dispatched');
});

it('responds 503 when webhook secret is missing and records verification_error', function (): void {
    Config::set('shopify.webhook_secret', '');

    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $body = '{"x":1}';
    $response = $this->call(
        'POST',
        '/api/webhooks/shopify',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'unit.myshopify.com',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'ignored', true)),
        ],
        $body,
    );

    $response->assertStatus(503)->assertJsonPath('ok', false);

    /** @var ShopifyWebhookLog|null $log */
    $log = ShopifyWebhookLog::query()->first();
    expect($log)->not->toBeNull();
    expect($log->verification_ok)->toBeFalse();
    expect($log->verification_error)->toBe('missing_webhook_secret');
});
