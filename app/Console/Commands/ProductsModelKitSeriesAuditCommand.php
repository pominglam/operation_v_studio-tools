<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ModelKitSeriesAuditService;
use App\Support\Products\ModelKitSeriesCatalog;
use Illuminate\Console\Command;

final class ProductsModelKitSeriesAuditCommand extends Command
{
    protected $signature = 'products:model-kit-series-audit
        {--include-archived : Include archived ERP rows}
        {--series= : Limit to one mk:series tag slug (e.g. gundam_unicorn)}
        {--min-confidence=low : Minimum confidence for auto proposals: high|medium|low}
        {--no-fandom : Skip gunpla.fandom.com series lookup (default when Plamod enabled)}
        {--no-plamod : Skip Plamod instock/preorder series lookup}
        {--with-fandom : Also use gunpla.fandom for SKUs without a Plamod series row}';

    protected $description = 'Audit ERP model-kit series vs title/subline inference rules; writes MD + JSON + apply template';

    public function handle(ModelKitSeriesAuditService $audit): int
    {
        $seriesFilter = $this->option('series');
        if (is_string($seriesFilter) && $seriesFilter !== '') {
            if (ModelKitSeriesCatalog::erpSeriesForTagSlug($seriesFilter) === null) {
                $this->warn("Unknown series slug '{$seriesFilter}' — still filtering by that slug.");
            }
        } else {
            $seriesFilter = null;
        }

        $minConfidence = (string) $this->option('min-confidence');
        if (! in_array($minConfidence, ['high', 'medium', 'low'], true)) {
            $this->error('--min-confidence must be high, medium, or low');

            return self::FAILURE;
        }

        $withPlamod = ! (bool) $this->option('no-plamod');
        $withFandom = $withPlamod
            ? (bool) $this->option('with-fandom')
            : ! (bool) $this->option('no-fandom');

        $result = $audit->audit(
            activeOnly: ! (bool) $this->option('include-archived'),
            seriesTagSlugFilter: $seriesFilter,
            minConfidence: $minConfidence,
            withFandom: $withFandom,
            withPlamod: $withPlamod,
        );
        $paths = $audit->writeReports($result);

        $this->info('Scanned: '.$result['scanned']);
        $this->info('OK: '.$result['ok']);
        if (($result['plamod_coverage'] ?? []) !== []) {
            $pc = $result['plamod_coverage'];
            $this->info(sprintf(
                'Plamod matched: %d / %d (change: %d, fill: %d)',
                $pc['matched_in_scope'] ?? 0,
                $pc['audit_scope'] ?? $result['scanned'],
                $pc['proposed_change'] ?? 0,
                $pc['proposed_fill'] ?? 0,
            ));
        }
        foreach ($result['summary'] as $category => $count) {
            $this->line(str_pad($category, 24).': '.$count);
        }
        $this->newLine();
        $this->info('Proposals (names): '.$paths['proposals']);
        $this->info('CSV: '.$paths['csv']);
        $this->info('Markdown: '.$paths['md']);
        $this->info('JSON: '.$paths['json']);
        $this->info('Apply template: '.$paths['approved_template']);

        return self::SUCCESS;
    }
}
