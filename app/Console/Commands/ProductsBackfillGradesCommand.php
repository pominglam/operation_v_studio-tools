<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductGradeBackfillService;
use Illuminate\Console\Command;

final class ProductsBackfillGradesCommand extends Command
{
    protected $signature = 'products:backfill-grades
        {--dry-run : Preview grade corrections without saving}
        {--sku=* : Limit to specific SKU(s)}
        {--yes : Do not prompt when applying changes}';

    protected $description = 'Correct products.grade from type/description for model kits (Gunpla grade buckets).';

    public function handle(ProductGradeBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $skus */
        $skus = array_values(array_filter(array_map(
            static fn (mixed $sku): string => trim((string) $sku),
            (array) $this->option('sku'),
        )));

        if (! $dryRun && ! (bool) $this->option('yes') && ! $this->confirm('Apply grade corrections?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $result = $service->backfill($skus !== [] ? $skus : null, $dryRun);

        foreach ($result['rows'] as $row) {
            $from = $row['from'] ?? '(null)';
            $this->line("{$row['sku']}: {$from} -> {$row['to']}");
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$verb} {$result['matched']} product(s).");

        return self::SUCCESS;
    }
}
