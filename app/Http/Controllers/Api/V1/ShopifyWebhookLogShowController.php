<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ShopifyWebhookLogResource;
use App\Services\Shopify\Admin\Webhooks\ShopifyWebhookLogQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShopifyWebhookLogShowController extends Controller
{
    public function __invoke(int $id, Request $request, ShopifyWebhookLogQueryService $service): JsonResponse
    {
        $log = $service->findById($id);
        if ($log === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $request->merge(['include_payload' => true]);

        return ShopifyWebhookLogResource::make($log)->response();
    }
}
