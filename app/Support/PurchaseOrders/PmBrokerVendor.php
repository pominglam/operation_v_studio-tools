<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

final class PmBrokerVendor
{
    /** @var list<string> */
    public const VENDORS = [
        'Dspiae',
        'Stedi',
        'Other/multi',
    ];

    public static function isPmBrokerVendor(string $vendor): bool
    {
        $normalized = strtolower(trim($vendor));

        foreach (self::VENDORS as $allowed) {
            if ($normalized === strtolower($allowed)) {
                return true;
            }
        }

        return false;
    }

    public static function isOtherMulti(string $vendor): bool
    {
        return strtolower(trim($vendor)) === 'other/multi';
    }

    /**
     * Product vendor assigned when a PO import creates a new catalog row.
     * Other/multi POs leave product.vendor blank for manual assignment on the PO detail page.
     */
    public static function productVendorForNewImportProduct(string $poVendor): ?string
    {
        if (self::isOtherMulti($poVendor)) {
            return null;
        }

        $trimmed = trim($poVendor);

        return $trimmed !== '' ? $trimmed : null;
    }
}
