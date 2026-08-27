<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductTaxonomyResearchDispatchService;
use App\Services\Products\ProductTaxonomyResearchService;
use Illuminate\Console\Command;

final class ProductsTaxonomyResearchCommand extends Command
{
    protected $signature = 'products:taxonomy-research
        {--research-version=taxonomy-v1 : Research ruleset version}
        {--queue : Queue the research run instead of processing immediately}';

    protected $description = 'Create evidence-backed canonical taxonomy proposals for every product';

    public function handle(
        ProductTaxonomyResearchService $research,
        ProductTaxonomyResearchDispatchService $dispatch,
    ): int {
        $version = trim((string) $this->option('research-version'));
        if ($version === '') {
            $this->error('The research version cannot be blank.');

            return self::INVALID;
        }

        if ((bool) $this->option('queue')) {
            $run = $dispatch->dispatch($version);
            $this->info("Queued taxonomy research run {$run->uuid}.");

            return self::SUCCESS;
        }

        $this->info('Researching taxonomy for all products, including archived records...');
        $result = $research->researchAll($version);
        $this->newLine();
        $this->info("Processed: {$result->processed}");
        $this->info("Proposed: {$result->proposed}");
        $this->info("Failed: {$result->failed}");
        $this->info("Run: {$result->run->uuid}");

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
