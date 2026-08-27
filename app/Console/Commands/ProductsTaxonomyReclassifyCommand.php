<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductTaxonomyReclassifyService;
use Illuminate\Console\Command;

final class ProductsTaxonomyReclassifyCommand extends Command
{
    protected $signature = 'products:taxonomy-reclassify
        {--run-id= : Optional research run id; defaults to latest completed run}
        {--patterns=keychain,figures,dspiae-mp : Comma-separated pattern groups to scan}
        {--no-auto-approve : Leave reclassified rows in proposed status}';

    protected $description = 'Re-open verified taxonomy rows when current rules disagree with applied product values';

    public function handle(ProductTaxonomyReclassifyService $reclassify): int
    {
        $runId = $this->option('run-id');
        $parsedRunId = is_numeric($runId) ? (int) $runId : null;
        $patterns = array_values(array_filter(array_map(
            static fn (string $pattern): string => trim($pattern),
            explode(',', (string) $this->option('patterns')),
        )));

        $result = $reclassify->reclassifyVerifiedMismatches(
            $parsedRunId,
            $patterns,
            ! (bool) $this->option('no-auto-approve'),
        );

        $this->info("Queued mismatches: {$result['queued']}");
        $this->info("Reclassified: {$result['refreshed']}");
        $this->info("Auto-verified: {$result['approved']}");
        $this->info("Skipped: {$result['skipped']}");
        $this->info("Failed: {$result['failed']}");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
