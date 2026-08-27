<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

use InvalidArgumentException;

final class CustomAsiaOrderCustomerMessageTemplate
{
    public const PLACEHOLDER_PRODUCT_NAME = '{product_name}';

    public const PLACEHOLDER_PRICE = '{price}';

    public const PLACEHOLDER_DEPOSIT_PERCENT = '{deposit_percent}';

    /** @var list<string> */
    public const REQUIRED_PLACEHOLDERS = [
        self::PLACEHOLDER_PRODUCT_NAME,
        self::PLACEHOLDER_PRICE,
        self::PLACEHOLDER_DEPOSIT_PERCENT,
    ];

    public static function defaultBody(): string
    {
        return <<<'TEXT'
{product_name} — Special Order

Price: {price}

We source from reputable stores and ship with a reliable carrier. Boxes normally arrive in good condition, though shipping wear is always possible. Significant box damage would be very unusual and we haven't encountered it so far, but box condition cannot be guaranteed.
Missing or damaged parts are extremely unlikely. The kit must be inspected at pickup, before leaving the store. If there is an issue, a discount of up to 10% may be offered at our discretion.
A {deposit_percent}% non-refundable deposit is required to place the order. Once ordered, the deposit is forfeited if you decide not to complete the purchase for any reason.

Please confirm you're okay with these conditions and I'll place the order.
TEXT;
    }

    public static function assertPlaceholders(string $body): void
    {
        foreach (self::REQUIRED_PLACEHOLDERS as $placeholder) {
            if (! str_contains($body, $placeholder)) {
                throw new InvalidArgumentException("Template must include {$placeholder}.");
            }
        }
    }

    public static function render(
        string $template,
        string $productName,
        string $priceLabel,
        string $depositPercentLabel,
    ): string {
        return str_replace(
            [
                self::PLACEHOLDER_PRODUCT_NAME,
                self::PLACEHOLDER_PRICE,
                self::PLACEHOLDER_DEPOSIT_PERCENT,
            ],
            [
                $productName,
                $priceLabel,
                $depositPercentLabel,
            ],
            $template,
        );
    }
}
