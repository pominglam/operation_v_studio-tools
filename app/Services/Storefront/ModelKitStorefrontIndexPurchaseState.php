<?php

declare(strict_types=1);

namespace App\Services\Storefront;

final class ModelKitStorefrontIndexPurchaseState
{
    /**
     * @param  array{closed: bool, offer: bool}  $preorder
     * @return array{available: bool, preorderClosed: bool, openPreorder: bool}
     */
    public static function from(array $preorder, int $sellable): array
    {
        $closed = $preorder['closed'];
        $open = $preorder['offer'] && ! $closed;

        return [
            'available' => $sellable > 0,
            'preorderClosed' => $closed,
            'openPreorder' => $open,
        ];
    }
}
