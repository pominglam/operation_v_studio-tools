<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerIdentityKeys
{
    /**
     * @param  list<string>  $denylist
     * @return list<string>
     */
    public static function fromOrder(?string $gid, ?string $email, ?string $phone, array $denylist = []): array
    {
        $keys = [];
        $gid = is_string($gid) ? trim($gid) : '';
        if ($gid !== '' && str_starts_with($gid, 'gid://shopify/Customer/')) {
            $keys[] = 'gid:'.$gid;
        }

        $emailKey = CustomerEmailNormalizer::normalize($email, $denylist);
        if ($emailKey !== null) {
            $keys[] = 'email:'.$emailKey;
        }

        $phoneKey = CustomerPhoneNormalizer::digits($phone);
        if ($phoneKey !== null) {
            $keys[] = 'phone:'.$phoneKey;
        }

        return $keys;
    }
}
