<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductPreferredDescriptionSourceRequest;
use App\Services\Products\ProductPreferredDescriptionSourceService;
use Illuminate\Http\JsonResponse;

final class ProductPreferredDescriptionSourceController extends Controller
{
    public function __invoke(string $id, ProductPreferredDescriptionSourceRequest $request, ProductPreferredDescriptionSourceService $service): JsonResponse
    {
        $source = $request->validated('preferred_description_source');
        $source = is_string($source) ? trim($source) : null;
        $source = $source !== '' ? $source : null;
        $manualDescriptionHtml = $request->validated('manual_description_html');
        $manualDescriptionHtml = is_string($manualDescriptionHtml) ? trim($manualDescriptionHtml) : null;
        $manualDescriptionHtml = $manualDescriptionHtml !== '' ? $manualDescriptionHtml : null;

        $product = $service->setForProduct($id, $source, $manualDescriptionHtml);

        return response()->json([
            'ok' => true,
            'data' => [
                'product_uuid' => (string) $product->uuid,
                'preferred_description_source' => $product->preferred_description_source,
            ],
        ]);
    }
}
