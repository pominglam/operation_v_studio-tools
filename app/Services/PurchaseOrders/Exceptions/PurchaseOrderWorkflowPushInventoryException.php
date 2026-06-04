<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders\Exceptions;

use RuntimeException;

final class PurchaseOrderWorkflowPushInventoryException extends RuntimeException
{
    /**
     * @param  list<array{sku: string, reason: string}>  $issues
     */
    public function __construct(
        string $message,
        private readonly array $issues = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{sku: string, reason: string}>
     */
    public function issues(): array
    {
        return $this->issues;
    }
}
