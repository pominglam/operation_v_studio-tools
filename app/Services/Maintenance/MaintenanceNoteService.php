<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\Models\MaintenanceNote;

final class MaintenanceNoteService
{
    public const DEFAULT_KEY = 'default';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    public function getDefault(): ?MaintenanceNote
    {
        return $this->notes->findByKey(self::DEFAULT_KEY);
    }

    public function upsertDefault(?string $body): MaintenanceNote
    {
        return $this->notes->upsert(self::DEFAULT_KEY, $body);
    }
}
