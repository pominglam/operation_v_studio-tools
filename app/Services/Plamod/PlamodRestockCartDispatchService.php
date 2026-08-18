<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\SyncPlamodRestockCartJob;

final class PlamodRestockCartDispatchService
{
    public function __construct(
        private readonly PlamodRestockCartLineBuilder $lines,
        private readonly PlamodRestockCartRunLogger $logger,
        private readonly PlamodScraperHealthService $health,
    ) {}

    /**
     * @param  array<int, string>  $skus
     * @return array{ok: bool, cart_run_id: int|null, line_count?: int, error_message?: string}
     */
    public function dispatch(array $skus): array
    {
        if ($this->logger->hasActiveRun()) {
            return [
                'ok' => false,
                'cart_run_id' => null,
                'error_message' => 'A PLAMOD cart run is already active.',
            ];
        }

        $ready = $this->health->assertRestockCartReady();
        if (! $ready['ok']) {
            return [
                'ok' => false,
                'cart_run_id' => null,
                'error_message' => (string) ($ready['error_message'] ?? 'Plamod scraper is not ready.'),
            ];
        }

        $uniqueSkus = array_values(array_unique(array_map(static fn (string $sku): string => trim($sku), $skus)));
        $cartLines = $this->lines->buildLines($uniqueSkus, includeZeroIncludedNew: true);

        if ($cartLines === []) {
            return [
                'ok' => false,
                'cart_run_id' => null,
                'error_message' => 'No selected restock lines qualified for PLAMOD cart automation.',
            ];
        }

        if (count($cartLines) !== count($uniqueSkus)) {
            $foundSkus = array_column($cartLines, 'sku');
            $invalid = array_values(array_diff($uniqueSkus, $foundSkus));
            $sample = implode(', ', array_slice($invalid, 0, 5));
            $suffix = count($invalid) > 5 ? '…' : '';

            return [
                'ok' => false,
                'cart_run_id' => null,
                'error_message' => 'Some selected SKUs are not eligible for PLAMOD cart automation: '.$sample.$suffix,
            ];
        }

        $run = $this->logger->queue();
        $this->logger->progress($run, [
            'requested_skus' => $uniqueSkus,
            'requested_lines' => $cartLines,
        ]);
        SyncPlamodRestockCartJob::dispatch((int) $run->id);

        return [
            'ok' => true,
            'cart_run_id' => (int) $run->id,
            'line_count' => count($cartLines),
        ];
    }
}
