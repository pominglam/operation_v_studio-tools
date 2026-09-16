<?php

declare(strict_types=1);

namespace App\DAL\Customers;

use App\Support\Customers\ShopifyOrderIdentityRow;

interface ShopifyOrderIdentityRepository
{
    /**
     * @return list<ShopifyOrderIdentityRow>
     */
    public function eligibleIdentityRows(): array;

    /**
     * @param  list<string>  $gids
     * @return array<string, string>
     */
    public function customerDisplayNamesByGid(array $gids): array;
}
