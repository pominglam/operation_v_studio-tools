<?php

declare(strict_types=1);

namespace App\DTOs\Plamod;

final readonly class PlamodPreorderInterestResult
{
    public function __construct(
        public int $requested,
        public int $updated,
        public bool $notInterested,
    ) {}
}
