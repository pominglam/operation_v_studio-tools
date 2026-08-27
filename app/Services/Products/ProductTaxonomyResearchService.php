<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductTaxonomyRepository;
use App\DTOs\Products\ProductTaxonomyProposalDTO;
use App\DTOs\Products\ProductTaxonomyResearchResultDTO;
use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Models\ProductTaxonomyResearchRun;
use App\Services\Products\Exceptions\ProductTaxonomyVerificationStateException;
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
