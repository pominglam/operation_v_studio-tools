<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ShopifyWebhookLogResource;
use App\Services\Shopify\Admin\Webhooks\ShopifyWebhookLogQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ShopifyWebhookLogIndexController extends Controller
{
    public function __invoke(Request $request, ShopifyWebhookLogQueryService $service): AnonymousResourceCollection
    {
        $paginator = $service->paginate(
            $request->only(['topic', 'verification_ok', 'processing_status', 'since', 'until']),
            (int) $request->integer('per_page', 50),
        );

        return ShopifyWebhookLogResource::collection($paginator);
    }
}
