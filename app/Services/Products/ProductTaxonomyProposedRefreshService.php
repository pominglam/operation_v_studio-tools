<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductTaxonomyRepository;
use App\Models\ProductTaxonomyResearchRun;
use App\Models\ProductTaxonomyVerification;
use App\Support\Products\AgentTestSkuGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProductTaxonomyProposedRefreshService
{
    private const AUTO_APPROVE_ACTOR = 'taxonomy-auto-approve';

    public function __construct(
        private readonly ProductTaxonomyRepository $taxonomy,
        private readonly ProductTaxonomyDerivationService $derivation,
        private readonly ProductTaxonomyEvidenceEnrichmentService $enrichment,
        private readonly ProductTaxonomyBulkApprovalService $bulkApproval,
    ) {}

    /**
     * @return array{refreshed: int, approved: int, skipped: int, failed: int}
     */
    public function refreshLatestRun(
        ?int $researchRunId = null,
        bool $includeOverridden = true,
        bool $autoApprove = true,
    ): array {
        $runId = $researchRunId ?? $this->latestCompletedRunId();
        if ($runId === null) {
            return ['refreshed' => 0, 'approved' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $statuses = ['proposed'];
        if ($includeOverridden) {
            $statuses[] = 'overridden';
        }

        $refreshed = 0;
        $approved = 0;
        $skipped = 0;
        $failed = 0;

        ProductTaxonomyVerification::query()
            ->where('research_run_id', $runId)
            ->whereIn('status', $statuses)
            ->with('product.externalContents')
            ->orderBy('id')
            ->chunkById(100, function ($verifications) use (&$refreshed, &$approved, &$skipped, &$failed, $autoApprove): void {
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

                    try {
                        $wasProposed = false;
                        DB::transaction(function () use ($verification, $product, &$wasProposed): void {
                            $locked = $this->taxonomy->findVerificationByUuidForUpdate($verification->uuid);
                            if (! in_array($locked->status, ['proposed', 'overridden'], true)) {
                                return;
                            }
                            $wasProposed = $locked->status === 'proposed';

                            $proposal = $this->enrichment->enrich(
                                $product,
                                $this->derivation->derive($product),
                            );
                            $locked->proposed_values_json = $proposal->values;
                            $locked->evidence_json = $proposal->evidence;
                            $locked->overall_confidence = $proposal->overallConfidence;
                            $locked->operator_notes = $this->mergeNotes(
                                $locked->operator_notes,
                                $proposal->notes,
                            );
                            $this->taxonomy->saveVerification($locked);
                        });
                        $refreshed++;

                        if ($autoApprove && $wasProposed) {
                            $verification->refresh();
                            if ($this->bulkApproval->tryApprove(
                                $verification,
                                self::AUTO_APPROVE_ACTOR,
                                'Auto-verified from high-confidence taxonomy derivation.',
                            )) {
                                $approved++;
                            }
                        }
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            });

        return compact('refreshed', 'approved', 'skipped', 'failed');
    }

    private function latestCompletedRunId(): ?int
    {
        $id = ProductTaxonomyResearchRun::query()
            ->whereIn('status', ['completed', 'completed_with_errors'])
            ->latest('id')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<int, string>  $proposalNotes
     */
    private function mergeNotes(?string $existing, array $proposalNotes): ?string
    {
        $chunks = array_filter([
            is_string($existing) ? trim($existing) : '',
            ...array_map(static fn (string $note): string => trim($note), $proposalNotes),
        ]);

        if ($chunks === []) {
            return null;
        }

        return implode("\n", array_values(array_unique($chunks)));
    }
}
