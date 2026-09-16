<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SpecialOrders\SpecialOrderDeleteService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderDeleteController extends Controller
{
    public function __construct(
        private readonly SpecialOrderDeleteService $delete,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $this->delete->delete($id);

        return response()->json(['ok' => true]);
    }
}
