<?php

declare(strict_types=1);

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Shopify\ShopifyWebhookLog;
use App\Services\Maintenance\ExternalAccessSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

it('dispatches order webhook processing job for verified order topics', function (): void {
    Queue::fake();
    Config::set('shopify.webhook_secret', 'unit-test-secret');
    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $body = json_encode([
        'admin_graphql_api_id' => 'gid://shopify/Order/555',
        'id' => 555,
    ], JSON_THROW_ON_ERROR);
    $hmac = base64_encode(hash_hmac('sha256', $body, 'unit-test-secret', true));

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
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        ],
        $body,
    );

    $response->assertStatus(200);

    Queue::assertPushed(ProcessShopifyOrderWebhookJob::class, function (ProcessShopifyOrderWebhookJob $job): bool {
        return $job->orderGid === 'gid://shopify/Order/555';
    });
});

it('marks webhook failed when order gid is missing', function (): void {
    Queue::fake();
    Config::set('shopify.webhook_secret', 'unit-test-secret');
    app(ExternalAccessSettingsService::class)->setEnabled(false);

    $body = '{"note":"no order id"}';
    $hmac = base64_encode(hash_hmac('sha256', $body, 'unit-test-secret', true));

    $this->call(
        'POST',
        '/api/webhooks/shopify',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/updated',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'unit.myshopify.com',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        ],
        $body,
    )->assertStatus(200);

    /** @var ShopifyWebhookLog|null $log */
    $log = ShopifyWebhookLog::query()->first();
    expect($log?->processing_status)->toBe('failed');

    Queue::assertNothingPushed();
});
