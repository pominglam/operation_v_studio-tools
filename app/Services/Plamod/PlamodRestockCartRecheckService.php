<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodRestockCartRun;
use App\Services\Products\Http\PlamodScraper;

final class PlamodRestockCartRecheckService
{
    public function __construct(
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recheck(): array
    {
        /** @var PlamodRestockCartRun|null $run */
        $run = PlamodRestockCartRun::query()->orderByDesc('id')->first();
        if ($run === null) {
            return [
                'ok' => false,
                'error_message' => 'No PLAMOD cart run exists to recheck.',
            ];
        }

        if (in_array((string) $run->status, ['queued', 'running'], true)) {
            return [
                'ok' => false,
                'error_message' => 'PLAMOD cart automation is still running. Wait for it to finish before rechecking.',
            ];
        }

        $counts = $run->counts_json ?? [];
        $report = is_array($counts['report'] ?? null) ? $counts['report'] : [];
        $lines = is_array($report['lines'] ?? null) ? $report['lines'] : [];
        if ($lines === []) {
            return [
                'ok' => false,
                'error_message' => 'Latest cart run has no verification report to recheck.',
            ];
        }

        $cartBefore = is_array($report['cart_before'] ?? null) ? $report['cart_before'] : [];
        $result = $this->scraper->restockVerifyCart([
            'cart_before' => $cartBefore,
            'lines' => $lines,
        ]);

        if (($result['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error_message' => (string) ($result['error_message'] ?? 'PLAMOD cart recheck failed.'),
            ];
        }

        $updatedReport = is_array($result['report'] ?? null) ? $result['report'] : [];
        $summary = is_array($updatedReport['summary'] ?? null) ? $updatedReport['summary'] : [];
        $allVerified = ($summary['all_verified'] ?? false) === true;
        $errorSummary = $allVerified ? null : $this->buildIncompleteVerificationSummary($summary);

        $run->forceFill([
            'error_summary' => $errorSummary,
            'counts_json' => array_merge($counts, [
                'report' => $updatedReport,
                'summary' => $summary,
                'all_verified' => $allVerified,
                'recheck_duration_ms' => $result['duration_ms'] ?? null,
            ]),
        ])->save();

        return [
            'ok' => true,
            'cart_run_id' => (int) $run->id,
            'report' => $updatedReport,
            'summary' => $summary,
            'all_verified' => $allVerified,
            'error_summary' => $errorSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function buildIncompleteVerificationSummary(array $summary): string
    {
        $verified = (int) ($summary['verified'] ?? 0);
        $requested = (int) ($summary['requested_lines'] ?? 0);
        $partial = (int) ($summary['partial'] ?? 0);
        $overAdded = (int) ($summary['over_added'] ?? 0);
        $missing = (int) ($summary['missing'] ?? 0);
        $failed = (int) ($summary['add_failed'] ?? 0);

        return "Cart verification incomplete: {$verified}/{$requested} verified, {$overAdded} over-added, {$partial} partial, {$missing} missing, {$failed} add failed.";
    }
}
