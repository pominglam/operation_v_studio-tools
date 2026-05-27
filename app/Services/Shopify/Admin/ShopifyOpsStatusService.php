<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\DAL\Shopify\ShopifySyncStateRepository;
use App\Jobs\Shopify\PullShopifyInventoryToProductsJob;
use App\Jobs\Shopify\RebuildProductDemandRollupsJob;
use App\Jobs\Shopify\ShopifyOrderHistoricalBackfillJob;
use App\Jobs\Shopify\ShopifyOrderReconcileJob;
use App\Models\Shopify\ShopifySyncLog;
use App\Models\Shopify\ShopifyWebhookLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ShopifyOpsStatusService
{
    public const string SYNC_KEY_ORDERS_RECONCILE = 'orders_reconcile';

    public const string SYNC_KEY_ORDERS_HISTORICAL = 'orders_historical';

    public const string SYNC_KEY_DEMAND_REBUILD = 'demand_rebuild_rollups';

    public const string SYNC_KEY_INVENTORY_PULL = 'inventory_pull_to_products';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
        private readonly ShopifySyncStateRepository $syncState,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $state = $this->syncState->findByKey(ShopifySettingsService::SYNC_KEY_ORDERS);
        $intervalHours = $this->resolveOrderReconcileIntervalHours();
        $lastSuccess = $state?->last_success_at;

        /** @var Carbon|null $lastWebhook */
        $lastWebhook = ShopifyWebhookLog::query()->max('created_at');

        $nextReconcileDue = null;
        if ($lastSuccess !== null) {
            $nextReconcileDue = $lastSuccess->copy()->addHours($intervalHours);
        }

        return [
            'order_reconcile_interval_hours' => $intervalHours,
            'orders_last_success_at' => optional($state?->last_success_at)->toISOString(),
            'orders_high_water_updated_at' => optional($state?->high_water_updated_at)->toISOString(),
            'orders_last_error' => $state?->last_error,
            'next_order_reconcile_due_at' => $nextReconcileDue?->toISOString(),
            'last_webhook_received_at' => $lastWebhook instanceof Carbon
                ? $lastWebhook->toISOString()
                : (is_string($lastWebhook) ? Carbon::parse($lastWebhook)->toISOString() : null),
            'tasks' => [
                $this->taskSnapshot(
                    self::SYNC_KEY_ORDERS_RECONCILE,
                    'Scheduled order reconcile',
                    ShopifyOrderReconcileJob::class,
                ),
                $this->taskSnapshot(
                    self::SYNC_KEY_ORDERS_HISTORICAL,
                    'Repull historical orders',
                    ShopifyOrderHistoricalBackfillJob::class,
                ),
                $this->taskSnapshot(
                    self::SYNC_KEY_DEMAND_REBUILD,
                    'Rebuild demand rollups',
                    RebuildProductDemandRollupsJob::class,
                ),
                $this->taskSnapshot(
                    self::SYNC_KEY_INVENTORY_PULL,
                    'Pull Shopify inventory',
                    PullShopifyInventoryToProductsJob::class,
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSnapshot(string $syncKey, string $label, string $jobClass): array
    {
        /** @var ShopifySyncLog|null $lastLog */
        $lastLog = ShopifySyncLog::query()
            ->where('sync_key', $syncKey)
            ->orderByDesc('id')
            ->first();

        $queuedInJobs = $this->isJobQueued($jobClass);
        $status = $this->deriveTaskStatus($lastLog, $queuedInJobs);

        return [
            'key' => $syncKey,
            'label' => $label,
            'status' => $status,
            'queued' => $status === 'queued',
            'last_started_at' => optional($lastLog?->started_at)->toISOString(),
            'last_finished_at' => optional($lastLog?->finished_at)->toISOString(),
            'duration_ms' => $lastLog?->duration_ms,
            'records_fetched' => $lastLog?->records_fetched,
            'records_updated' => $lastLog?->records_updated,
            'records_failed' => $lastLog?->records_failed,
            'error_summary' => $lastLog?->error_summary,
            'counts_json' => $lastLog?->counts_json,
        ];
    }

    private function deriveTaskStatus(?ShopifySyncLog $log, bool $queued): string
    {
        if ($log !== null && $log->status === 'running' && $log->finished_at === null) {
            return 'running';
        }

        if ($log !== null && $log->status === 'queued' && $log->finished_at === null) {
            return 'queued';
        }

        if ($queued) {
            return 'queued';
        }

        if ($log === null) {
            return 'never';
        }

        return (string) $log->status;
    }

    private function isJobQueued(string $jobClass): bool
    {
        $needle = class_basename($jobClass);

        return DB::table('jobs')
            ->where('payload', 'like', '%'.$needle.'%')
            ->exists();
    }

    private function resolveOrderReconcileIntervalHours(): int
    {
        $note = $this->notes->findByKey(ShopifySettingsService::KEY_ORDER_RECONCILE_INTERVAL_HOURS);
        $raw = is_string($note?->body) ? trim($note->body) : '';
        if ($raw === '' || ! ctype_digit($raw)) {
            return 12;
        }

        return max(1, min(168, (int) $raw));
    }
}
