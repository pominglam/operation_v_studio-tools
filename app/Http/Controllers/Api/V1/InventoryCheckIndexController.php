<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InventoryCheckResource;
use App\Services\Products\InventoryCheckQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InventoryCheckIndexController extends Controller
{
    public function __construct(
        private readonly InventoryCheckQueryService $inventoryChecks,
    ) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->query('per_page') ?? 50);
        $perPage = max(1, min($perPage, 200));

        return InventoryCheckResource::collection(
            $this->inventoryChecks->paginate($perPage),
        );
    }
}
