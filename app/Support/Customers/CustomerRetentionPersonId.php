<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerRetentionPersonId
{
    /**
     * @param  list<string>  $keys
     */
    public static function fromKeys(array $keys): string
    {
        $unique = array_values(array_unique($keys));
        sort($unique);

        return hash('sha256', implode("\n", $unique));
    }
}
