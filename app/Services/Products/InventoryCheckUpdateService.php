<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\InventoryChecks\InventoryCheckRepository;
use App\Models\InventoryCheck;

final class InventoryCheckUpdateService
{
    public function __construct(
        private readonly InventoryCheckRepository $inventoryChecks,
    ) {}

    public function updateNotes(string $uuid, ?string $notes): InventoryCheck
    {
        $session = $this->inventoryChecks->findByUuidOrFail($uuid);
        $trimmed = is_string($notes) ? trim($notes) : '';
        $session->notes = $trimmed !== '' ? $trimmed : null;

        return $this->inventoryChecks->save($session);
    }
}
