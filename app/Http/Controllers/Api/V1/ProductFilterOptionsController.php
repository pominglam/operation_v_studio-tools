<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductFilterOptionsService;
use Illuminate\Http\JsonResponse;

final class ProductFilterOptionsController extends Controller
{
    public function __construct(
        private readonly ProductFilterOptionsService $options,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->options->all(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
