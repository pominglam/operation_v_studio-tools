<?php

declare(strict_types=1);

namespace App\Support\StoreEvents;

final readonly class StoreEventWriteData
{
    public function __construct(
        public string $name,
        public string $startsOn,
        public string $endsOn,
        public ?string $notes,
        public bool $cancelled,
    ) {}
}
