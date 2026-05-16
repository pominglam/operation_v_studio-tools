<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifySyncLog;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ShopifyErpSyncCoordinator
{
    /** @var array<string, ShopifySyncRunnerInterface> */
    private array $runnerByKey;

    /**
     * @param  iterable<ShopifySyncRunnerInterface>  $runners
     */
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        iterable $runners,
    ) {
        $map = [];
        foreach ($runners as $runner) {
            $map[$runner->key()] = $runner;
        }
        $this->runnerByKey = $map;
    }

    public function sync(string $target): ShopifySyncLog
    {
        $plan = $this->planKeys($target);
        if ($plan === []) {
            throw new InvalidArgumentException("Unknown Shopify sync target: {$target}");
        }
        $started = microtime(true);
        $syncLog = ShopifySyncLog::query()->create([
            'sync_key' => $target === 'full' ? 'full' : $target,
            'status' => 'running',
            'started_at' => now(),
            'checkpoint_json' => ['targets' => $plan],
            'counts_json' => [],
        ]);

        /** @var array<string, array<string, int>> $counts */
        $counts = [];

        try {
            foreach ($plan as $key) {
                $runner = $this->runnerByKey[$key] ?? null;
                if ($runner === null) {
                    continue;
                }
                Log::channel('shopify')->info('shopify.sync.segment.start', ['sync_log_id' => $syncLog->id, 'segment' => $key]);
                $segment = new ShopifySyncMetrics;
                $runner->run($this->client, $segment);
                $counts[$key] = $segment->toArray();
                $syncLog->records_fetched += $counts[$key]['fetched'];
                $syncLog->records_created += $counts[$key]['created'];
                $syncLog->records_updated += $counts[$key]['updated'];
                $syncLog->records_failed += $counts[$key]['failed'];
                $syncLog->counts_json = $counts;
                $syncLog->save();
                Log::channel('shopify')->info('shopify.sync.segment.finish', ['sync_log_id' => $syncLog->id, 'segment' => $key]);
            }

            $syncLog->status = 'completed';
        } catch (Throwable $e) {
            $syncLog->status = 'failed';
            $syncLog->error_summary = mb_substr($e->getMessage(), 0, 5000);
            Log::channel('shopify')->error('shopify.sync.failed', [
                'sync_log_id' => $syncLog->id,
                'exception' => $e->getMessage(),
            ]);
        }

        $syncLog->finished_at = now();
        $syncLog->duration_ms = (int) round((microtime(true) - $started) * 1000);
        $syncLog->counts_json = $counts;
        $syncLog->save();
        $syncLog->refresh();

        return $syncLog;
    }

    /**
     * @return list<string>
     */
    private function planKeys(string $target): array
    {
        $order = ['locations', 'products', 'inventory_levels', 'orders', 'customers', 'collections'];
        if ($target === 'full') {
            return $order;
        }
        if (isset($this->runnerByKey[$target])) {
            return [$target];
        }

        return [];
    }
}
