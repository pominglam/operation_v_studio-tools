<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomOrders\CustomAsiaOrderDeleteService;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderDeleteController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderDeleteService $delete,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $this->delete->delete($id);

        return response()->json(['ok' => true]);
    }
}
