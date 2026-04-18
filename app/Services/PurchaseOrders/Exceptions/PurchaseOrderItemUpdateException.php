<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders\Exceptions;

use RuntimeException;

final class PurchaseOrderItemUpdateException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(
        string $message,
        public readonly array $issues = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
