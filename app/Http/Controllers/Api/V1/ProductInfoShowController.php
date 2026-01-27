<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductInfoResource;
use App\Services\Products\ProductInfoQueryService;

final class ProductInfoShowController extends Controller
{
    public function __invoke(string $id, ProductInfoQueryService $service): ProductInfoResource
    {
        $data = $service->getByProductUuid($id);

        return ProductInfoResource::make($data);
    }
}

