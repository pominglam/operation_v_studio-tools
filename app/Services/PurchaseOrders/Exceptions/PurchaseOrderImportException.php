<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders\Exceptions;

use RuntimeException;

final class PurchaseOrderImportException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(
        string $message,
        private readonly array $issues = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function issues(): array
    {
        return $this->issues;
    }
}


