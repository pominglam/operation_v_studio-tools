<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InventoryCheckResource;
use App\Services\Products\InventoryCheckQueryService;

final class InventoryCheckShowController extends Controller
{
    public function __construct(
        private readonly InventoryCheckQueryService $inventoryChecks,
    ) {}

    public function __invoke(string $id): InventoryCheckResource
    {
        return InventoryCheckResource::make(
            $this->inventoryChecks->findByUuidOrFail($id),
        );
    }
}
