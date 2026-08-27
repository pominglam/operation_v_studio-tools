<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductTaxonomyBulkApprovalService;
use Illuminate\Console\Command;

final class ProductsTaxonomyApproveCommand extends Command
{
    protected $signature = 'products:taxonomy-approve
        {--exclude-test-skus=1 : Skip E2E/UI test SKUs}
        {--require-kit-manufacturer=1 : Skip model kits without a manufacturer}
        {--dry-run : Count eligible proposals without applying them}
        {--operator=local-operator : Actor recorded on verification rows}';

    protected $description = 'Apply high-confidence canonical taxonomy proposals to ERP products (90% kits, 88% workshop supplies/paints/tools)';

    public function handle(ProductTaxonomyBulkApprovalService $approval): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $approval->approveEligible(
            trim((string) $this->option('operator')),
            $this->booleanOption('exclude-test-skus'),
            $this->booleanOption('require-kit-manufacturer'),
            $dryRun,
            $dryRun ? null : 'Bulk approved from high-confidence research.',
        );

        $this->info($dryRun ? 'Dry-run taxonomy approval counts:' : 'Applied taxonomy proposals:');
        $this->info("Approved: {$result->approved}");
        $this->info("Skipped: {$result->skipped}");
        $this->info("Failed: {$result->failed}");

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function booleanOption(string $name): bool
    {
        $value = $this->option($name);
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
