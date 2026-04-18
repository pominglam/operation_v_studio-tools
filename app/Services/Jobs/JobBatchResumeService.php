<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\DAL\Jobs\JobBatchItemRepository;
use App\Services\Products\ProductsRecrawlSelectedService;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JobBatchResumeService
{
    public function __construct(
        private readonly JobBatchItemRepository $items,
        private readonly ProductsRecrawlSelectedService $recrawl,
    ) {}

    /**
     * Resume a stalled `recrawl_selected_products` batch by creating a new batch for unfinished items.
     *
     * @return array{resumed: bool, new_batch_id: string|null, queued: int, reason: string|null}
     */
    public function resume(string $batchId): array
    {
        $batchId = trim($batchId);
        if ($batchId === '') {
            throw new BadRequestHttpException('missing_batch_id');
        }

        $batch = Bus::findBatch($batchId);
        if ($batch === null) {
            throw new NotFoundHttpException('batch_not_found');
        }

        if ((bool) $batch->cancelledAt) {
            return [
                'resumed' => false,
                'new_batch_id' => null,
                'queued' => 0,
                'reason' => 'batch_cancelled',
            ];
        }

        if ($batch->name !== 'recrawl_selected_products') {
            throw new BadRequestHttpException('unsupported_batch_type');
        }

        // Ensure our item table is up to date before checking statuses.
        $this->items->backfillFromQueueTables($batchId);

        $running = $this->items->listProductUuidsByStatus($batchId, ['running']);
        if ($running !== []) {
            throw new ConflictHttpException('batch_still_running');
        }

        $unfinished = $this->items->listProductUuidsByStatus($batchId, ['queued', 'failed']);
        if ($unfinished === []) {
            return [
                'resumed' => false,
                'new_batch_id' => null,
                'queued' => 0,
                'reason' => 'nothing_to_resume',
            ];
        }

        $sources = $this->inferSourcesFromDebugLog($batchId);
        if ($sources === []) {
            throw new BadRequestHttpException('cannot_infer_sources');
        }

        $res = $this->recrawl->recrawlSelected($unfinished, $sources);

        return [
            'resumed' => true,
            'new_batch_id' => $res->batchId,
            'queued' => $res->queued,
            'reason' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function inferSourcesFromDebugLog(string $batchId): array
    {
        $debug = $this->items->getAnyDebugLog($batchId);
        if (! is_string($debug) || trim($debug) === '') {
            return [];
        }

        $firstLine = trim(explode("\n", $debug, 2)[0] ?? '');
        if (! preg_match('/^\\[job\\]\\s+sources=([^\\s]+)\\s*$/', $firstLine, $m)) {
            // Fall back: scan any line.
            if (preg_match('/^\\[job\\]\\s+sources=([^\\s]+)\\s*$/m', $debug, $m2)) {
                $m = $m2;
            } else {
                return [];
            }
        }

        $raw = (string) ($m[1] ?? '');
        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '')));

        return $parts;
    }
}
