<?php

declare(strict_types=1);

namespace App\DTOs\TcgEvents;

use Illuminate\Support\Carbon;

final readonly class TcgEventsRefreshResultDTO
{
    public function __construct(
        public int $fetchedEvents,
        public int $upsertedEvents,
        public Carbon $fetchedAt,
    ) {}
}
