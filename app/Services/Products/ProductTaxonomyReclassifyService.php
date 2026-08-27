<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductTaxonomyRepository;
use App\Models\ProductTaxonomyVerification;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProductTaxonomyReclassifyService
{
    private const AUTO_APPROVE_ACTOR = 'taxonomy-auto-approve';

    public function __construct(
        private readonly ProductTaxonomyRepository $taxonomy,
        private readonly ProductTaxonomyDerivationService $derivation,
        private readonly ProductTaxonomyEvidenceEnrichmentService $enrichment,
        private readonly ProductTaxonomyBulkApprovalService $bulkApproval,
    ) {}

    /**
     * @param  array<int, string>  $patterns
     * @return array{queued: int, refreshed: int, approved: int, skipped: int, failed: int}
     */
    public function reclassifyVerifiedMismatches(
        ?int $researchRunId,
        array $patterns,
        bool $autoApprove = true,
    ): array {
        $runId = $researchRunId ?? $this->latestCompletedRunId();
        if ($runId === null) {
            return ['queued' => 0, 'refreshed' => 0, 'approved' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $queued = 0;
        $refreshed = 0;
        $approved = 0;
        $skipped = 0;
        $failed = 0;

        ProductTaxonomyVerification::query()
            ->where('research_run_id', $runId)
            ->where('status', 'verified')
            ->with('product.externalContents')
            ->orderBy('id')
            ->chunkById(100, function ($verifications) use (
                &$queued,
                &$refreshed,
                &$approved,
                &$skipped,
                &$failed,
                $patterns,
                $autoApprove,
            ): void {
                foreach ($verifications as $verification) {
                    $product = $verification->product;
                    if ($product === null) {
                        $skipped++;

                        continue;
                    }
                    if (AgentTestSkuGuard::isAgentTestSku((string) $product->sku, (string) $product->description)) {
                        $skipped++;

                        continue;
                    }
                    if (! $this->matchesPattern($product, $patterns)) {
                        continue;
                    }

                    $proposal = $this->enrichment->enrich(
                        $product,
                        $this->derivation->derive($product),
                    );
                    $derived = ProductTaxonomyFields::normalize($proposal->values);
                    $applied = ProductTaxonomyFields::fromProduct($product);
                    if (! ProductTaxonomyFields::valuesDiffer($derived, $applied)) {
                        continue;
                    }

                    $queued++;

                    try {
                        DB::transaction(function () use ($verification, $proposal, &$refreshed): void {
                            $locked = $this->taxonomy->findVerificationByUuidForUpdate($verification->uuid);
                            if ($locked->status !== 'verified') {
                                return;
                            }

                            $locked->status = 'proposed';
                            $locked->proposed_values_json = $proposal->values;
                            $locked->evidence_json = $proposal->evidence;
                            $locked->overall_confidence = $proposal->overallConfidence;
                            $this->taxonomy->saveVerification($locked);
                            $refreshed++;
                        });

                        if ($autoApprove) {
                            $verification->refresh();
                            if ($this->bulkApproval->tryApprove(
                                $verification,
                                self::AUTO_APPROVE_ACTOR,
                                'Reclassified from verified state using updated taxonomy rules.',
                            )) {
                                $approved++;
                            }
                        }
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            });

        return compact('queued', 'refreshed', 'approved', 'skipped', 'failed');
    }

    private function latestCompletedRunId(): ?int
    {
        $id = \App\Models\ProductTaxonomyResearchRun::query()
            ->whereIn('status', ['completed', 'completed_with_errors'])
            ->latest('id')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesPattern(\App\Models\Product $product, array $patterns): bool
    {
        $text = mb_strtoupper(implode(' ', array_filter([
            $product->sku,
            $product->description,
            $product->type,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
        $sku = mb_strtoupper(trim((string) $product->sku));

        foreach ($patterns as $pattern) {
            $matched = match ($pattern) {
                'keychain' => preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT|MASCOT KEYCHAIN)\b/', $text) === 1,
                'figures' => str_starts_with($sku, 'CCS') || preg_match('/\bCCS (?:TOYS|EVANGELION)\b/', $text) === 1,
                'dspiae-mp' => $sku === 'MP-05',
                default => false,
            };
            if ($matched) {
                return true;
            }
        }

        return false;
    }
}
