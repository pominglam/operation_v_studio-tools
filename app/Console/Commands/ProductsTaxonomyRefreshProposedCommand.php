<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductTaxonomyProposedRefreshService;
use Illuminate\Console\Command;

final class ProductsTaxonomyRefreshProposedCommand extends Command
{
    protected $signature = 'products:taxonomy-refresh-proposed
        {--run-id= : Optional research run id; defaults to latest completed run}
        {--proposed-only : Only refresh rows still in proposed status}
        {--no-auto-approve : Leave high-confidence rows in proposed status}';

    protected $description = 'Re-derive proposed taxonomy values for the latest research run using current rules';

    public function handle(ProductTaxonomyProposedRefreshService $refresh): int
    {
        $runId = $this->option('run-id');
        $parsedRunId = is_numeric($runId) ? (int) $runId : null;

        $result = $refresh->refreshLatestRun(
            $parsedRunId,
            ! (bool) $this->option('proposed-only'),
            ! (bool) $this->option('no-auto-approve'),
        );
        $this->info("Refreshed: {$result['refreshed']}");
        $this->info("Auto-verified: {$result['approved']}");
        $this->info("Skipped: {$result['skipped']}");
        $this->info("Failed: {$result['failed']}");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
