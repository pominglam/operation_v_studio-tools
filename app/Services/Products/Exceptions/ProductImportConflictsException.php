<?php

declare(strict_types=1);

namespace App\Services\Products\Exceptions;

final class ProductImportConflictsException extends \RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(
        string $message,
        private readonly array $issues,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function issues(): array
    {
        return $this->issues;
    }
}




