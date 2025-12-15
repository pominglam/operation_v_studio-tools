<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchRun;
use App\Models\PriceResearchRunLog;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentPriceResearchRunLogRepository implements PriceResearchRunLogRepository
{
    public function start(PriceResearchRun $run, Product $product, string $siteKey, string $siteName): PriceResearchRunLog
    {
        /** @var PriceResearchRunLog $log */
        $log = PriceResearchRunLog::query()->create([
            'run_id' => $run->id,
            'run_uuid' => $run->uuid,
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'sku' => $product->sku,
            'site_key' => $siteKey,
            'site_name' => $siteName,
            'status' => 'running',
            'started_at' => now(),
        ]);

        return $log;
    }

    public function finish(
        PriceResearchRunLog $log,
        string $status,
        ?string $productUrl,
        ?string $errorMessage,
        int $durationMs,
    ): PriceResearchRunLog {
        $log->status = $status;
        $log->product_url = $productUrl;
        $log->error_message = $errorMessage;
        $log->finished_at = now();
        $log->duration_ms = max(0, $durationMs);
        $log->save();

        return $log;
    }

    public function paginateForRunUuid(string $runUuid, int $perPage): LengthAwarePaginator
    {
        return PriceResearchRunLog::query()
            ->where('run_uuid', $runUuid)
            ->with(['product'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
