<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerChurnStatus
{
    public const ACTIVE = 'active';

    public const CHURNED = 'churned';

    /**
     * @return list<string>
     */
    public static function allowedFilters(): array
    {
        return [self::ACTIVE, self::CHURNED];
    }
}
