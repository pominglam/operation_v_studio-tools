<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodRestockCartRun;
use App\Services\Products\Http\PlamodScraper;

final class PlamodRestockCartRunService
{
    public const string QUEUE = 'plamod_sync';

    public function __construct(
        private readonly PlamodRestockCartLineBuilder $lines,
        private readonly PlamodScraper $scraper,
        private readonly PlamodRestockCartRunLogger $logger,
    ) {}

    public function run(int $runId): void
    {
        /** @var PlamodRestockCartRun|null $run */
        $run = PlamodRestockCartRun::query()->find($runId);
        if ($run === null) {
            return;
        }

        $cartLines = $this->cartLinesFor($run);
        if ($cartLines === []) {
            $this->logger->fail($run, 'No selected restock lines qualified for PLAMOD cart automation.');

            return;
        }

        $this->logger->markRunning($run);
        $this->logger->progress($run, [
            'phase' => 'starting',
            'items_total' => count($cartLines),
            'items_processed' => 0,
            'requested_lines' => $cartLines,
        ]);

        $result = $this->scraper->restockAddToCart($cartLines);

        if (($result['ok'] ?? false) !== true) {
            $this->logger->fail(
                $run,
                (string) ($result['error_message'] ?? 'PLAMOD cart automation failed.'),
                [
                    'phase' => 'failed',
                    'report' => $result['report'] ?? null,
                    'lines' => $result['lines'] ?? [],
                ],
            );

            return;
        }

        $report = is_array($result['report'] ?? null) ? $result['report'] : [];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $allVerified = ($summary['all_verified'] ?? false) === true;

        $this->logger->complete($run, [
            'phase' => 'completed',
            'report' => $report,
            'summary' => $summary,
            'all_verified' => $allVerified,
            'duration_ms' => $result['duration_ms'] ?? null,
        ]);

        if (! $allVerified) {
            $verified = (int) ($summary['verified'] ?? 0) + (int) ($summary['already_satisfied'] ?? 0);
            $requested = (int) ($summary['requested_lines'] ?? count($cartLines));
            $partial = (int) ($summary['partial'] ?? 0);
            $overAdded = (int) ($summary['over_added'] ?? 0);
            $missing = (int) ($summary['missing'] ?? 0);
            $failed = (int) ($summary['add_failed'] ?? 0);
            $run->forceFill([
                'error_summary' => "Cart verification incomplete: {$verified}/{$requested} verified, {$overAdded} over-added, {$partial} partial, {$missing} missing, {$failed} add failed.",
            ])->save();
        }
    }

    /**
     * @return array<int, array{sku: string, qty: int, product_name?: string, source?: string}>
     */
    private function cartLinesFor(PlamodRestockCartRun $run): array
    {
        $stored = $run->counts_json['requested_lines'] ?? null;
        if (is_array($stored) && $stored !== []) {
            /** @var array<int, array{sku: string, qty: int, product_name?: string, source?: string}> $stored */
            return array_values($stored);
        }

        /** @var array<int, string>|null $requestedSkus */
        $requestedSkus = $run->counts_json['requested_skus'] ?? null;

        return $this->lines->buildLines(
            is_array($requestedSkus) ? $requestedSkus : null,
            includeZeroIncludedNew: true,
        );
    }
}
