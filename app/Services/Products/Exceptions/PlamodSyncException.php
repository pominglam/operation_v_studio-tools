<?php

declare(strict_types=1);

namespace App\Services\Products\Exceptions;

final class PlamodSyncException extends \RuntimeException
{
    public static function downloadFailed(string $message): self
    {
        return new self($message);
    }

    public static function zipMissing(): self
    {
        return new self('Plamod download did not produce a ZIP file.');
    }

    public static function zipOpenFailed(): self
    {
        return new self('Failed to open ZIP.');
    }
}
