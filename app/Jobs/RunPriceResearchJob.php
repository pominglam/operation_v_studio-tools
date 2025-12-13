<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PriceResearch\PriceResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class RunPriceResearchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, string>|null $productUuids
     */
    public function __construct(
        public string $runUuid,
        public ?array $productUuids,
        public bool $force,
    ) {
    }

    public function handle(PriceResearchService $research): void
    {
        $summary = $research->run($this->productUuids, $this->force, $this->runUuid);

        Log::info('price_research.completed', [
            'summary' => $summary,
        ]);
    }
}


