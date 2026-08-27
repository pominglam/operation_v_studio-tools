<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

use App\Models\CustomAsiaOrder;

final class CustomAsiaOrderVisualKind
{
    public const string CUSTOMER = 'customer';

    public const string PRODUCT = 'product';

    public const string MERCHANDISER_ORDER_PROOF = 'merchandiser-order-proof';

    /** @var list<string> */
    public const ALL = [
        self::CUSTOMER,
        self::PRODUCT,
        self::MERCHANDISER_ORDER_PROOF,
    ];

    public static function normalize(?string $kind): ?string
    {
        if ($kind === null) {
            return null;
        }

        $kind = strtolower(trim($kind));

        return in_array($kind, self::ALL, true) ? $kind : null;
    }

    /** @return array{path: string, mime: string, filename: string} */
    public static function columns(string $kind): array
    {
        return match (self::normalize($kind)) {
            self::CUSTOMER => [
                'path' => 'customer_visual_path',
                'mime' => 'customer_visual_mime',
                'filename' => 'customer_visual_filename',
            ],
            self::PRODUCT => [
                'path' => 'product_visual_path',
                'mime' => 'product_visual_mime',
                'filename' => 'product_visual_filename',
            ],
            self::MERCHANDISER_ORDER_PROOF => [
                'path' => 'merchandiser_order_proof_path',
                'mime' => 'merchandiser_order_proof_mime',
                'filename' => 'merchandiser_order_proof_filename',
            ],
            default => throw new \InvalidArgumentException('Invalid visual kind.'),
        };
    }

    public static function pathOn(CustomAsiaOrder $order, string $kind): ?string
    {
        $columns = self::columns($kind);
        $value = $order->{$columns['path']};

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function mimeOn(CustomAsiaOrder $order, string $kind): ?string
    {
        $columns = self::columns($kind);
        $value = $order->{$columns['mime']};

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function filenameOn(CustomAsiaOrder $order, string $kind): ?string
    {
        $columns = self::columns($kind);
        $value = $order->{$columns['filename']};

        return is_string($value) && $value !== '' ? $value : null;
    }
}
