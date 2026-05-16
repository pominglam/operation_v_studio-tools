<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Webhooks;

use App\Events\Shopify\ShopifyWebhookReceived;
use App\Models\Shopify\ShopifyWebhookLog;
use Illuminate\Support\Facades\Log;

final class ShopifyWebhookIngressService
{
    public function ingest(ShopifyWebhookIngressDto $dto): ShopifyWebhookIngressResult
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($dto->rawBody, true);
        $payloadOk = is_array($decoded);

        $log = ShopifyWebhookLog::query()->create([
            'shop_domain' => $dto->shopDomain !== '' ? $dto->shopDomain : 'unknown',
            'topic' => $dto->topic !== '' ? $dto->topic : 'UNKNOWN',
            'shopify_webhook_id' => $dto->webhookId,
            'request_id' => $dto->requestId,
            'verification_ok' => false,
            'processing_status' => 'received',
            'verification_error' => null,
            'payload_json' => $payloadOk ? $decoded : null,
        ]);

        $secretRaw = config('shopify.webhook_secret');
        if (! is_string($secretRaw) || trim($secretRaw) === '') {
            Log::channel('shopify')->error('shopify.webhook.secret_missing');
            $log->forceFill([
                'verification_error' => 'missing_webhook_secret',
            ])->save();

            return new ShopifyWebhookIngressResult(httpStatus: 503, verified: false, log: $log);
        }

        $calculated = base64_encode(hash_hmac('sha256', $dto->rawBody, $secretRaw, true));
        if ($dto->hmacHeader === '' || ! hash_equals($calculated, $dto->hmacHeader)) {
            Log::channel('shopify')->warning('shopify.webhook.verify_failed', [
                'webhook_log_id' => $log->id,
                'topic' => $log->topic,
                'shop_domain' => $log->shop_domain,
            ]);
            $log->forceFill([
                'verification_ok' => false,
                'verification_error' => 'invalid_hmac',
            ])->save();

            return new ShopifyWebhookIngressResult(httpStatus: 401, verified: false, log: $log);
        }

        $log->forceFill([
            'verification_ok' => true,
            'verification_error' => null,
            'processing_status' => 'verified',
        ])->save();

        event(new ShopifyWebhookReceived($log));

        $log->forceFill([
            'processing_status' => 'dispatched',
        ])->save();

        return new ShopifyWebhookIngressResult(httpStatus: 200, verified: true, log: $log);
    }
}
