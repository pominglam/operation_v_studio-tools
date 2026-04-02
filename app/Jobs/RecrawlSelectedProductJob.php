<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DAL\Products\ProductRepository;
use App\Services\Jobs\JobBatchItemService;
use App\Services\PriceResearch\PriceResearchService;
use App\Services\Products\Bandai\BandaiContentSyncService;
use App\Services\Products\GundamHangar\GundamHangarContentSyncService;
use App\Services\Products\GundamPlanet\GundamPlanetContentSyncService;
use App\Services\Products\Hlj\HljContentSync;
use App\Services\Products\Newtype\NewtypeContentSyncService;
use App\Services\Products\PlamodAssetSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class RecrawlSelectedProductJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled()];
    }

    /**
     * @param  array<int, string>  $sources
     */
    public function __construct(
        public string $syncUuid,
        public string $productUuid,
        public array $sources,
    ) {
        $this->onQueue('pdp_sync');
    }

    public function handle(
        ProductRepository $products,
        PlamodAssetSyncService $plamod,
        HljContentSync $hlj,
        GundamPlanetContentSyncService $gundamplanet,
        NewtypeContentSyncService $newtype,
        GundamHangarContentSyncService $gundamhangar,
        BandaiContentSyncService $bandai,
        PriceResearchService $prices,
        JobBatchItemService $batchItems,
    ): void {
        $batchId = $this->batch()?->id;
        if ($this->batch()?->cancelled()) {
            if (is_string($batchId) && $batchId !== '') {
                $batchItems->markSkipped($batchId, $this->productUuid, 'cancelled');
            }
            return;
        }

        $sources = array_values(array_unique(array_filter(array_map('strval', $this->sources), static fn (string $v): bool => trim($v) !== '')));
        if (is_string($batchId) && $batchId !== '') {
            $batchItems->markRunning($batchId, $this->productUuid, $this->syncUuid);
            $batchItems->appendDebugLog($batchId, $this->productUuid, '[job] sources='.implode(',', $sources));
        }

        $trace = null;
        if (is_string($batchId) && $batchId !== '') {
            $trace = function (string $line) use ($batchItems, $batchId): void {
                $batchItems->appendDebugLog($batchId, $this->productUuid, $line);
            };
        }

        $didWork = false;
        $failedSources = [];

        $append = function (string $source, string $event, array $fields = []) use ($trace): void {
            if (! is_callable($trace)) return;

            $parts = [];
            foreach ($fields as $k => $v) {
                if (! is_string($k) || trim($k) === '') continue;
                if ($v === null) continue;
                $val = is_string($v) ? $v : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
                $val = trim($val);
                if ($val === '') continue;
                $val = str_replace(["\r", "\n"], ' ', $val);
                if (mb_strlen($val) > 400) {
                    $val = mb_substr($val, 0, 400).'…';
                }
                $parts[] = "{$k}={$val}";
            }
            $suffix = $parts !== [] ? (' '.implode(' ', $parts)) : '';
            $trace("[{$source}][{$event}]{$suffix}");
        };

        $runSource = function (string $source, callable $fn) use (&$didWork, &$failedSources, $append): void {
            $t0 = microtime(true);
            $append($source, 'start');
            try {
                $out = $fn();
                $durationMs = (int) round((microtime(true) - $t0) * 1000);

                $extra = [];
                if (is_array($out)) {
                    $extra = $out;
                } elseif (is_bool($out)) {
                    $extra = ['result' => $out ? 'ok' : 'skipped'];
                } elseif ($out !== null) {
                    $extra = ['result' => (string) $out];
                }
                $append($source, 'done', [...$extra, 'duration_ms' => (string) $durationMs]);
                if (($extra['result'] ?? 'ok') !== 'skipped') {
                    $didWork = true;
                }
            } catch (\Throwable $e) {
                $durationMs = (int) round((microtime(true) - $t0) * 1000);
                $failedSources[] = $source;
                $append($source, 'error', [
                    'duration_ms' => (string) $durationMs,
                    'message' => $e->getMessage(),
                ]);
            }
        };

        $wantPlamod = in_array('plamod', $sources, true);
        $wantHlj = in_array('hlj', $sources, true);
        $wantGundamPlanet = in_array('gundamplanet', $sources, true);
        $wantNewtype = in_array('newtype', $sources, true);
        $wantGundamHangar = in_array('gundamhangar', $sources, true);
        $wantBandai = in_array('bandai', $sources, true);
        $wantPrices = in_array('competitor_price_research', $sources, true);

        $product = null;
        if ($wantHlj || $wantGundamPlanet || $wantNewtype || $wantGundamHangar) {
            $product = $products->findByUuidOrFail($this->productUuid);
        }

        if ($wantPlamod) {
            $runSource('plamod', function () use ($plamod): array {
                $res = $plamod->syncByProductUuid($this->productUuid, true);
                $assetCount = is_array($res->assets ?? null) ? count($res->assets) : 0;
                $desc = $res->content?->description_html;
                $hasDesc = is_string($desc) && trim($desc) !== '';
                return [
                    'result' => 'ok',
                    'assets' => (string) $assetCount,
                    'has_description' => $hasDesc ? 'true' : 'false',
                ];
            });
        }

        if ($wantHlj && $product !== null) {
            $runSource('hlj', function () use ($hlj, $product): array {
                $hlj->syncForProduct($product);
                return ['result' => 'ok'];
            });
        }

        if ($wantGundamPlanet && $product !== null) {
            $runSource('gundamplanet', function () use ($gundamplanet, $product, $trace): array {
                $gundamplanet->syncForProduct($product, $this->syncUuid, $trace);
                return ['result' => 'ok'];
            });
        }

        if ($wantNewtype && $product !== null) {
            $runSource('newtype', function () use ($newtype, $product, $trace): array {
                $newtype->syncForProduct($product, $this->syncUuid, $trace);
                return ['result' => 'ok'];
            });
        }

        if ($wantGundamHangar && $product !== null) {
            $runSource('gundamhangar', function () use ($gundamhangar, $product, $trace): array {
                $gundamhangar->syncForProduct($product, $this->syncUuid, $trace);
                return ['result' => 'ok'];
            });
        }

        if ($wantBandai) {
            $runSource('bandai', function () use ($bandai): array {
                $ok = $bandai->syncByProductUuid($this->productUuid);
                return ['result' => $ok ? 'ok' : 'skipped'];
            });
        }

        if ($wantPrices) {
            $runSource('competitor_price_research', function () use ($prices): array {
                $res = $prices->run([$this->productUuid], true, null, null);
                return [
                    'result' => 'ok',
                    'processed' => (string) ($res['processed'] ?? 0),
                    'quotes_written' => (string) ($res['quotes_written'] ?? 0),
                ];
            });
        }

        if (is_string($batchId) && $batchId !== '') {
            if ($didWork) {
                if ($failedSources !== []) {
                    $append('job', 'summary', [
                        'result' => 'partial',
                        'failed_sources' => implode(',', array_values(array_unique($failedSources))),
                    ]);
                } else {
                    $append('job', 'summary', ['result' => 'ok']);
                }
                $batchItems->markSucceeded($batchId, $this->productUuid);
            } else {
                if ($failedSources !== []) {
                    $append('job', 'summary', [
                        'result' => 'failed',
                        'failed_sources' => implode(',', array_values(array_unique($failedSources))),
                    ]);
                    $batchItems->markFailed($batchId, $this->productUuid, 'all_sources_failed');
                } else {
                    $append('job', 'summary', ['result' => 'skipped']);
                    $batchItems->markSkipped($batchId, $this->productUuid, 'no_sources_found');
                }
            }
        }

        Log::info('products.recrawl.completed', [
            'sync_uuid' => $this->syncUuid,
            'product_uuid' => $this->productUuid,
            'sources' => $sources,
            'did_work' => $didWork,
            'failed_sources' => array_values(array_unique($failedSources)),
        ]);
    }
}

