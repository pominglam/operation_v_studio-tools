<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerCadenceStatus
{
    public const ON_CADENCE = 'on_cadence';

    public const DUE = 'due';

    public const LAPSED = 'lapsed';

    /**
     * @return list<string>
     */
    public static function allowedFilters(): array
    {
        return [self::ON_CADENCE, self::DUE, self::LAPSED];
    }
}
