<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Customers\CustomerRetentionCrosscheckService;
use App\Services\Customers\CustomerRetentionShopifyLiveAuditor;
use Illuminate\Console\Command;

final class CustomerRetentionCrosscheckCommand extends Command
{
    protected $signature = 'shopify:customer-retention-crosscheck {--live : Also page live Shopify Admin orders}';

    protected $description = 'Cross-check customer retention math (ERP snapshot, API summary, optional live Shopify)';

    public function handle(
        CustomerRetentionCrosscheckService $internal,
        CustomerRetentionShopifyLiveAuditor $live,
    ): int {
        $internalResult = $internal->runInternal();
        $this->printChecks('ERP + API', $internalResult['checks']);
        $ok = $internalResult['ok'];

        if ((bool) $this->option('live')) {
            $this->info('Paging live Shopify orders…');
            $liveResult = $live->run();
            $this->printChecks('Live Shopify', $liveResult['checks']);
            foreach ($liveResult['mismatches'] as $line) {
                $this->warn($line);
            }
            $ok = $ok && $liveResult['ok'];
        }

        if (! $ok) {
            $this->error('Customer retention cross-check failed.');

            return self::FAILURE;
        }

        $this->info('Customer retention cross-check passed.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{name: string, ok: bool, detail: string}>  $checks
     */
    private function printChecks(string $title, array $checks): void
    {
        $this->newLine();
        $this->info($title);
        $this->table(
            ['Check', 'Result', 'Detail'],
            array_map(
                static fn (array $check): array => [
                    $check['name'],
                    $check['ok'] ? 'PASS' : 'FAIL',
                    $check['detail'],
                ],
                $checks,
            ),
        );
    }
}
