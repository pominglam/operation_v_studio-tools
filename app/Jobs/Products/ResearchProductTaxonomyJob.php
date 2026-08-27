<?php

declare(strict_types=1);

namespace App\Jobs\Products;

use App\Services\Products\ProductTaxonomyResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ResearchProductTaxonomyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly string $researchRunUuid,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProductTaxonomyResearchService $research): void
    {
        $research->researchQueuedRun($this->researchRunUuid);
    }
}
