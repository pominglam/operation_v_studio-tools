<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SpecialOrders\SpecialOrderCustomerMessageTemplateService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderCustomerMessageTemplateShowController extends Controller
{
    public function __construct(
        private readonly SpecialOrderCustomerMessageTemplateService $templates,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->templates->toArray(),
        ]);
    }
}
