<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlamodProductResource;
use App\Services\Products\PlamodAssetQueryService;

final class ProductPlamodShowController extends Controller
{
    public function __invoke(string $id, PlamodAssetQueryService $service): PlamodProductResource
    {
        $data = $service->getByProductUuid($id);

        return PlamodProductResource::make($data);
    }
}
