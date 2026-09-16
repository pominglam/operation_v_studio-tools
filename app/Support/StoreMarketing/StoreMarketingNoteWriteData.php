<?php

declare(strict_types=1);

namespace App\Support\StoreMarketing;

final readonly class StoreMarketingNoteWriteData
{
    public function __construct(
        public string $name,
        public string $happenedOn,
        public ?string $notes,
    ) {}
}
