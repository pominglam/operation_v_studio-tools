<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\InventoryCheckDeleteService;
use Illuminate\Http\JsonResponse;

final class InventoryCheckDeleteController extends Controller
{
    public function __construct(
        private readonly InventoryCheckDeleteService $deleter,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $this->deleter->deleteByUuid($id);

        return response()->json(['ok' => true, 'message' => 'Inventory check deleted.']);
    }
}
