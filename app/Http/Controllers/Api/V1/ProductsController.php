<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductsQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductsController extends Controller
{
    public function __construct(
        private readonly ProductsQueryService $products,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        return ProductResource::collection(
            $this->products->paginate($perPage),
        );
    }
}


