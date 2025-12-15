<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\DAL\PriceResearch\ProductLookupRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RunPriceResearchRequest;
use App\Jobs\RunPriceResearchJob;
use App\Services\PriceResearch\PriceResearchService;
use Illuminate\Http\JsonResponse;

final class PriceResearchRunController extends Controller
{
    public function __construct(
        private readonly PriceResearchService $research,
        private readonly ProductLookupRepository $products,
        private readonly PriceResearchRunRepository $runs,
    ) {}

    public function __invoke(RunPriceResearchRequest $request): JsonResponse
    {
        /** @var array<int, string>|null $ids */
        $ids = $request->validated('ids');
        /** @var array<int, string>|null $siteKeys */
        $siteKeys = $request->validated('site_keys');
        $force = (bool) $request->boolean('force', false);

        $ttlDays = max(1, (int) config('price_research.ttl_days', 14));
        $totalSites = $siteKeys !== null ? count($siteKeys) : count((array) config('price_research.sites', []));
        if ($ids === null) {
            $existingIds = null;
            $totalProducts = (int) \App\Models\Product::query()->count();
        } else {
            $existing = $this->products->findByUuids($ids);
            $existingIds = $existing->pluck('uuid')->values()->all();
            $totalProducts = $existing->count();
        }

        $run = $this->runs->create($force, $ttlDays, $totalSites, $totalProducts, $existingIds, $siteKeys);

        // In tests (QUEUE_CONNECTION=sync), run inline for deterministic assertions.
        if (config('queue.default') === 'sync') {
            $summary = $this->research->run($existingIds, $force, $run->uuid, $siteKeys);

            return response()->json([
                'data' => $summary,
                'run_id' => $run->uuid,
                'queued' => false,
            ]);
        }

        RunPriceResearchJob::dispatch($run->uuid, $existingIds, $force, $siteKeys)
            ->onConnection('database')
            ->onQueue('price_research');

        return response()->json([
            'queued' => true,
            'run_id' => $run->uuid,
        ], 202);
    }
}
