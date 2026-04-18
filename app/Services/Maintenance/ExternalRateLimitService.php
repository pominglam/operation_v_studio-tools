<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use Illuminate\Support\Facades\Cache;

final class ExternalRateLimitService
{
    public const string KEY = 'external_hits_per_minute';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    public function getHitsPerMinute(): int
    {
        $note = $this->notes->findByKey(self::KEY);
        $raw = $note?->body;
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '' || ! ctype_digit($raw)) {
            return max(1, (int) config('price_research.rate_limit.per_site_per_minute', 10));
        }

        return max(1, (int) $raw);
    }

    public function setHitsPerMinute(int $hitsPerMinute): int
    {
        $hitsPerMinute = max(1, min($hitsPerMinute, 120));
        $this->notes->upsert(self::KEY, (string) $hitsPerMinute);
        Cache::forget('settings:external_hits_per_minute');

        return $hitsPerMinute;
    }
}
