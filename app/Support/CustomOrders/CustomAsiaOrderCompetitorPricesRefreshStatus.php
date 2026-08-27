<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderCompetitorPricesRefreshStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public static function isInProgress(?string $status): bool
    {
        return $status === self::QUEUED || $status === self::RUNNING;
    }
}
