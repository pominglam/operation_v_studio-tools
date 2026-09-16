<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductSavedViewResource;
use App\Services\Products\ProductSavedViewService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductSavedViewIndexController extends Controller
{
    public function __construct(
        private readonly ProductSavedViewService $views,
    ) {}

    public function __invoke(): AnonymousResourceCollection
    {
        return ProductSavedViewResource::collection($this->views->list());
    }
}
