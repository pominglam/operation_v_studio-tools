<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifyOrderIndexRequest;
use App\Http\Resources\Api\V1\ShopifyOrderResource;
use App\Services\Shopify\Admin\Orders\ShopifyOrderQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ShopifyOrderIndexController extends Controller
{
    public function __invoke(
        ShopifyOrderIndexRequest $request,
        ShopifyOrderQueryService $service,
    ): AnonymousResourceCollection {
        $result = $service->paginate($request->filters());

        return ShopifyOrderResource::collection($result['paginator'])->additional([
            'summary' => $result['summary'],
        ]);
    }
}
