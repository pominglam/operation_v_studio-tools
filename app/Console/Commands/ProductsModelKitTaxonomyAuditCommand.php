<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ModelKitTaxonomyAuditService;
use Illuminate\Console\Command;

final class ProductsModelKitTaxonomyAuditCommand extends Command
{
    protected $signature = 'products:model-kit-taxonomy-audit
        {--include-archived : Include archived ERP rows in the scan}';

    protected $description = 'Audit model-kit ERP taxonomy vs derivation rules and storefront mk:* tags; writes MD + JSON reports';

    public function handle(ModelKitTaxonomyAuditService $audit): int
    {
        $result = $audit->audit(activeOnly: ! (bool) $this->option('include-archived'));
        $paths = $audit->writeReports($result);

        $this->info('Scanned: '.$result['scanned']);
        $this->info('With storefront tags: '.$result['with_storefront_tags']);
        foreach ($result['summary'] as $category => $count) {
            $this->line(str_pad($category, 22).': '.$count);
        }
        $this->newLine();
        $this->info('Markdown: '.$paths['md']);
        $this->info('JSON: '.$paths['json']);

        return self::SUCCESS;
    }
}
