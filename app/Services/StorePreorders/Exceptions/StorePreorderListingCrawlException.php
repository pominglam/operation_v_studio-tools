<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Exceptions;

use RuntimeException;

final class StorePreorderListingCrawlException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'crawl_failed',
        public readonly ?string $host = null,
    ) {
        parent::__construct($message);
    }

    public static function noCrawler(string $host): self
    {
        return new self('No crawler for '.$host.'.', 'no_crawler', $host);
    }

    public static function failed(string $message, ?string $host = null): self
    {
        return new self($message, 'crawl_failed', $host);
    }
}
