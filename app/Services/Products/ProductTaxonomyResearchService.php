<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductTaxonomyRepository;
use App\DTOs\Products\ProductTaxonomyProposalDTO;
use App\DTOs\Products\ProductTaxonomyResearchResultDTO;
use App\DTOs\Products\ProductTaxonomyResearchSelectedResult;
use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Models\ProductTaxonomyResearchRun;
use App\Models\ProductTaxonomyVerification;
use App\Services\Products\Exceptions\ProductTaxonomyVerificationStateException;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProductTaxonomyResearchService
{
    /** @var array<int, string> */
    private const SOURCE_PRIORITY = [
        'bandai',
        'gundamplanet',
        'hlj',
        'newtype',
        'gundamhangar',
        'argama',
        'plamod',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTaxonomyRepository $taxonomy,
        private readonly ProductTaxonomyDerivationService $derivation,
        private readonly ProductTaxonomyEvidenceEnrichmentService $enrichment,
        private readonly ProductTaxonomyBulkApprovalService $bulkApproval,
    ) {}

    public function researchAll(string $researchVersion): ProductTaxonomyResearchResultDTO
    {
        $run = $this->taxonomy->createRun($researchVersion);

        return $this->processRun($run);
    }

    public function queueAll(string $researchVersion): ProductTaxonomyResearchRun
    {
        return $this->taxonomy->createQueuedRun($researchVersion);
    }

    /**
     * @param  array<int, string>  $verificationUuids
     * @param  array<int, string>|null  $fields
     */
    public function researchSelected(
        array $verificationUuids,
        ?array $fields = null,
    ): ProductTaxonomyResearchSelectedResult {
        $latestRunId = $this->latestCompletedRunId();
        if ($latestRunId === null) {
            return new ProductTaxonomyResearchSelectedResult(0, count($verificationUuids), 0);
        }

        $selectedFields = $this->normalizeResearchFields($fields);
        $researched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->taxonomy->findVerificationsByUuids($verificationUuids) as $verification) {
            if ($this->researchSelectedSkipReason($verification, $latestRunId) !== null) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($verification, $selectedFields, &$researched): void {
                    $locked = $this->taxonomy->findVerificationByUuidForUpdate($verification->uuid);
                    $product = $locked->product;
                    if ($product === null) {
                        throw new ProductTaxonomyVerificationStateException('Missing product for verification.');
                    }

                    $product->loadMissing('externalContents');
                    $proposal = $this->proposeForProduct($product);
                    $source = $this->preferredSource($product);
                    $merged = $this->mergePartialResearch($locked, $proposal, $selectedFields);

                    $locked->status = 'proposed';
                    $locked->previous_values_json = ProductTaxonomyFields::fromProduct($product);
                    $locked->proposed_values_json = $merged['values'];
                    $locked->evidence_json = $merged['evidence'];
                    $locked->overall_confidence = $merged['confidence'];
                    $locked->research_method = $source?->source ?? 'existing_metadata';
                    $locked->researched_at = now();
                    $locked->verified_at = null;
                    $locked->verified_by = null;
                    $locked->overridden_at = null;
                    $this->taxonomy->saveVerification($locked);
                    $researched++;
                });
            } catch (Throwable) {
                $failed++;
            }
        }

        $missing = count($verificationUuids) - $researched - $skipped - $failed;

        return new ProductTaxonomyResearchSelectedResult(
            $researched,
            $skipped + max(0, $missing),
            $failed,
        );
    }

    public function proposeForProduct(Product $product): ProductTaxonomyProposalDTO
    {
        $proposal = $this->enrichment->enrich($product, $this->derivation->derive($product));

        return $this->withStoredSource($product, $proposal);
    }

    public function researchQueuedRun(string $runUuid): ProductTaxonomyResearchResultDTO
    {
        $run = DB::transaction(function () use ($runUuid): ProductTaxonomyResearchRun {
            $run = $this->taxonomy->findRunByUuidForUpdate($runUuid);
            if ($run->status !== 'queued') {
                throw new ProductTaxonomyVerificationStateException(
                    'Only queued taxonomy research runs can be started.',
                );
            }
            $run->status = 'running';
            $run->started_at = now();

            return $this->taxonomy->saveRun($run);
        });

        return $this->processRun($run);
    }

    private function processRun(
        ProductTaxonomyResearchRun $run,
    ): ProductTaxonomyResearchResultDTO {
        $processed = 0;
        $proposed = 0;
        $failed = 0;
        $lastError = null;

        foreach ($this->products->listAllWithTaxonomySources() as $product) {
            try {
                $proposal = $this->enrichment->enrich($product, $this->derivation->derive($product));
                $proposal = $this->withStoredSource($product, $proposal);
                $source = $this->preferredSource($product);
                $verification = $this->taxonomy->createVerification(
                    $product,
                    $run,
                    $proposal,
                    $source?->source ?? 'existing_metadata',
                );
                $this->bulkApproval->tryApprove(
                    $verification,
                    'taxonomy-research',
                    'Auto-verified from high-confidence taxonomy research.',
                );
                $proposed++;
            } catch (Throwable $exception) {
                $failed++;
                $lastError = mb_substr($exception->getMessage(), 0, 1000);
            }
            $processed++;
            $run->checkpoint_json = ['last_product_id' => $product->id];
        }

        $run->status = $failed > 0 ? 'completed_with_errors' : 'completed';
        $run->counts_json = compact('processed', 'proposed', 'failed');
        $run->error_summary = $lastError;
        $run->completed_at = now();
        $run = $this->taxonomy->saveRun($run);

        return new ProductTaxonomyResearchResultDTO($run, $processed, $proposed, $failed);
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
     * @param  array<int, string>|null  $fields
     * @return array<int, string>
     */
    private function normalizeResearchFields(?array $fields): array
    {
        if ($fields === null || $fields === []) {
            return ProductTaxonomyFields::ALL;
        }

        $allowed = array_flip(ProductTaxonomyFields::ALL);
        $normalized = [];
        foreach ($fields as $field) {
            if (is_string($field) && isset($allowed[$field])) {
                $normalized[] = $field;
            }
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ProductTaxonomyFields::ALL;
    }

    /**
     * @param  array<int, string>  $selectedFields
     * @return array{
     *     values: array<string, mixed>,
     *     evidence: array<string, mixed>,
     *     confidence: int
     * }
     */
    private function mergePartialResearch(
        ProductTaxonomyVerification $verification,
        ProductTaxonomyProposalDTO $proposal,
        array $selectedFields,
    ): array {
        $existingValues = ProductTaxonomyFields::normalize(
            is_array($verification->proposed_values_json)
                ? $verification->proposed_values_json
                : ProductTaxonomyFields::fromProduct($verification->product),
        );
        $derivedValues = ProductTaxonomyFields::normalize($proposal->values);
        $mergedValues = $existingValues;

        /** @var array<string, mixed> $existingEvidence */
        $existingEvidence = is_array($verification->evidence_json) ? $verification->evidence_json : [];
        $mergedEvidence = $existingEvidence;

        foreach ($selectedFields as $field) {
            $mergedValues[$field] = $derivedValues[$field] ?? null;
            if (isset($proposal->evidence[$field])) {
                $mergedEvidence[$field] = $proposal->evidence[$field];
            }
        }

        return [
            'values' => $mergedValues,
            'evidence' => $mergedEvidence,
            'confidence' => $this->confidenceFromEvidence($mergedEvidence),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function confidenceFromEvidence(array $evidence): int
    {
        $scores = [];
        foreach ($evidence as $item) {
            if (! is_array($item) || ! isset($item['confidence'])) {
                continue;
            }
            $scores[] = (int) $item['confidence'];
        }

        if ($scores === []) {
            return 0;
        }

        return (int) round(array_sum($scores) / count($scores));
    }

    private function researchSelectedSkipReason(
        ProductTaxonomyVerification $verification,
        int $latestRunId,
    ): ?string {
        if ($verification->research_run_id !== $latestRunId) {
            return 'stale run';
        }

        $product = $verification->product;
        if ($product === null) {
            return 'missing product';
        }

        if (AgentTestSkuGuard::isAgentTestSku((string) $product->sku, (string) $product->description)) {
            return 'test sku';
        }

        return null;
    }

    private function withStoredSource(
        Product $product,
        ProductTaxonomyProposalDTO $proposal,
    ): ProductTaxonomyProposalDTO {
        $source = $this->preferredSource($product);
        if ($source === null || trim((string) $source->source_url) === '') {
            return $proposal;
        }

        $evidence = $proposal->evidence;
        foreach ($evidence as $field => $item) {
            if (is_string($item['source_url'] ?? null) && trim($item['source_url']) !== '') {
                continue;
            }
            $evidence[$field] = [
                ...$item,
                'source_url' => $source->source_url,
                'source_label' => ucfirst($source->source),
            ];
        }

        return new ProductTaxonomyProposalDTO(
            $proposal->values,
            $evidence,
            $proposal->overallConfidence,
            $proposal->notes,
        );
    }

    private function preferredSource(Product $product): ?ProductExternalContent
    {
        foreach (self::SOURCE_PRIORITY as $sourceName) {
            $match = $product->externalContents->first(
                static fn (ProductExternalContent $source): bool => $source->source === $sourceName
                    && trim((string) $source->source_url) !== '',
            );
            if ($match instanceof ProductExternalContent) {
                return $match;
            }
        }

        return $product->externalContents->first(
            static fn (ProductExternalContent $source): bool => trim((string) $source->source_url) !== '',
        );
    }
}
