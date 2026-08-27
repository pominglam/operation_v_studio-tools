<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Jobs\Products\ResearchProductTaxonomyJob;
use App\Models\ProductTaxonomyResearchRun;

final class ProductTaxonomyResearchDispatchService
{
    public function __construct(
        private readonly ProductTaxonomyResearchService $research,
    ) {}

    public function dispatch(string $researchVersion): ProductTaxonomyResearchRun
    {
        $run = $this->research->queueAll($researchVersion);
        ResearchProductTaxonomyJob::dispatch($run->uuid);

        return $run;
    }
}
