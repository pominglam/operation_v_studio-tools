<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SpecialOrders\SpecialOrderPricingCapsService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderPricingCapsShowController extends Controller
{
    public function __construct(
        private readonly SpecialOrderPricingCapsService $caps,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->caps->toArray(),
        ]);
    }
}
