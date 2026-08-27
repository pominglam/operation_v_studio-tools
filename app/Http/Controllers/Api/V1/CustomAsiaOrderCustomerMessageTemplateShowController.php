<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomOrders\CustomAsiaOrderCustomerMessageTemplateService;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderCustomerMessageTemplateShowController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderCustomerMessageTemplateService $templates,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->templates->toArray(),
        ]);
    }
}
