<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DAL\RuntimeSettings\RuntimeSettingRepository;
use App\Services\Products\Http\PlamodScraper;

final class PlamodRestockOrderVerifyService
{
    public const string RUNTIME_KEY = 'plamod_restock.order_verify_report';

    public function __construct(
        private readonly PlamodRestockCartLineBuilder $lines,
        private readonly PlamodRestockCartRunLogger $logger,
        private readonly PlamodScraperHealthService $health,
        private readonly PlamodScraper $scraper,
        private readonly RuntimeSettingRepository $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $stored = $this->readStoredReport();

        return [
            'ok' => true,
            'report' => $stored['report'] ?? null,
            'summary' => $stored['summary'] ?? null,
            'all_verified' => $stored['all_verified'] ?? null,
            'order_matches_cart' => $stored['order_matches_cart'] ?? null,
            'verified_at' => $stored['verified_at'] ?? null,
            'line_count' => $stored['line_count'] ?? null,
            'error_summary' => $stored['error_summary'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        if ($this->logger->hasActiveRun()) {
            return [
                'ok' => false,
                'error_message' => 'PLAMOD cart automation is still running. Wait for it to finish before verifying your order.',
            ];
        }

        $ready = $this->health->assertRestockCartReady();
        if (! $ready['ok']) {
            return [
                'ok' => false,
                'error_message' => (string) ($ready['error_message'] ?? 'Plamod scraper is not ready.'),
            ];
        }

        $cartLines = $this->lines->buildLines(includeZeroIncludedNew: true);
        if ($cartLines === []) {
            return [
                'ok' => false,
                'error_message' => 'No included restock lines to verify.',
            ];
        }

        $lineErrors = $this->logger->latestLineErrorMessages();
        $verifyLines = array_map(
            static fn (array $line): array => [
                'sku' => (string) ($line['sku'] ?? ''),
                'product_name' => (string) ($line['product_name'] ?? $line['sku'] ?? ''),
                'source' => (string) ($line['source'] ?? ''),
                'requested_qty' => (int) ($line['qty'] ?? 0),
                'add_status' => 'order_verify',
                'error_message' => $lineErrors[(string) ($line['sku'] ?? '')] ?? null,
            ],
            $cartLines,
        );

        $result = $this->scraper->restockVerifyCart([
            'cart_before' => [],
            'lines' => $verifyLines,
            'scope' => 'full_order',
        ]);

        if (($result['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error_message' => (string) ($result['error_message'] ?? 'PLAMOD full-order verification failed.'),
            ];
        }

        $report = is_array($result['report'] ?? null) ? $result['report'] : [];
        $report = $this->mergeLineErrors($report, $lineErrors);
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $allVerified = ($summary['all_verified'] ?? false) === true;
        $orderMatchesCart = ($summary['order_matches_cart'] ?? false) === true;
        $errorSummary = $orderMatchesCart ? null : $this->buildIncompleteSummary($summary);

        $payload = [
            'report' => $report,
            'summary' => $summary,
            'all_verified' => $allVerified,
            'order_matches_cart' => $orderMatchesCart,
            'verified_at' => is_string($report['verified_at'] ?? null) ? $report['verified_at'] : now()->toIso8601String(),
            'line_count' => count($verifyLines),
            'error_summary' => $errorSummary,
        ];
        $this->settings->putString(self::RUNTIME_KEY, json_encode($payload, JSON_THROW_ON_ERROR));

        return array_merge(['ok' => true], $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function readStoredReport(): array
    {
        $raw = $this->settings->getString(self::RUNTIME_KEY);
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    private function mergeLineErrors(array $report, array $messages): array
    {
        $lines = is_array($report['lines'] ?? null) ? $report['lines'] : [];
        $report['lines'] = array_map(static function (mixed $line) use ($messages): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $status = (string) ($line['verification_status'] ?? '');
            if (in_array($status, ['verified', 'already_satisfied'], true)) {
                $line['error_message'] = null;

                return $line;
            }
            $sku = (string) ($line['sku'] ?? '');
            $line['error_message'] = $line['error_message'] ?? $messages[$sku] ?? null;

            return $line;
        }, $lines);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function buildIncompleteSummary(array $summary): string
    {
        $verified = (int) ($summary['verified'] ?? 0) + (int) ($summary['already_satisfied'] ?? 0);
        $requested = (int) ($summary['requested_lines'] ?? 0);
        $partial = (int) ($summary['partial'] ?? 0);
        $overAdded = (int) ($summary['over_added'] ?? 0);
        $missing = (int) ($summary['missing'] ?? 0);
        $failed = (int) ($summary['add_failed'] ?? 0);
        $extra = (int) ($summary['extra_cart_lines'] ?? 0);

        $message = "Full order verification incomplete: {$verified}/{$requested} lines match";
        if ($overAdded > 0) {
            $message .= ", {$overAdded} over-added";
        }
        if ($partial > 0) {
            $message .= ", {$partial} partial";
        }
        if ($missing > 0) {
            $message .= ", {$missing} missing";
        }
        if ($failed > 0) {
            $message .= ", {$failed} add failed";
        }
        if ($extra > 0) {
            $message .= ", {$extra} extra cart line(s)";
        }

        return $message.'.';
    }
}
