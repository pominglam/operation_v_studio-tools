<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\Webhooks\ShopifyWebhookIngressDto;
use App\Services\Shopify\Admin\Webhooks\ShopifyWebhookIngressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, ShopifyWebhookIngressService $ingress): JsonResponse
    {
        $dto = new ShopifyWebhookIngressDto(
            rawBody: $request->getContent(),
            topic: trim((string) $request->headers->get('X-Shopify-Topic', '')),
            shopDomain: trim((string) $request->headers->get('X-Shopify-Shop-Domain', '')),
            webhookId: self::normalizeHeader($request->headers->get('X-Shopify-Webhook-Id')),
            requestId: self::normalizeHeader($request->headers->get('X-Shopify-Request-Id')),
            hmacHeader: trim((string) $request->headers->get('X-Shopify-Hmac-Sha256', '')),
        );

        $result = $ingress->ingest($dto);
        $payload = $result->verified
            ? ['ok' => true, 'webhook_log_id' => $result->log->id]
            : ['ok' => false];

        return response()->json($payload, $result->httpStatus);
    }

    private static function normalizeHeader(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
