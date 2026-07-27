<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryCheckUpdateRequest;
use App\Http\Resources\Api\V1\InventoryCheckResource;
use App\Services\Products\InventoryCheckUpdateService;

final class InventoryCheckUpdateController extends Controller
{
    public function __construct(
        private readonly InventoryCheckUpdateService $updates,
    ) {}

    public function __invoke(InventoryCheckUpdateRequest $request, string $id): InventoryCheckResource
    {
        $notes = $request->validated('notes');
        $notes = is_string($notes) || $notes === null ? $notes : null;

        return InventoryCheckResource::make(
            $this->updates->updateNotes($id, $notes),
        );
    }
}
