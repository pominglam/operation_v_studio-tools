<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Webhooks;

use App\Models\Shopify\ShopifyWebhookLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ShopifyWebhookLogQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ShopifyWebhookLog>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $q = ShopifyWebhookLog::query()->orderByDesc('id');

        $topic = isset($filters['topic']) && is_string($filters['topic']) ? trim($filters['topic']) : '';
        if ($topic !== '') {
            $q->where('topic', $topic);
        }

        if (array_key_exists('verification_ok', $filters) && $filters['verification_ok'] !== null && $filters['verification_ok'] !== '') {
            $q->where('verification_ok', filter_var($filters['verification_ok'], FILTER_VALIDATE_BOOLEAN));
        }

        $status = isset($filters['processing_status']) && is_string($filters['processing_status'])
            ? trim($filters['processing_status']) : '';
        if ($status !== '') {
            $q->where('processing_status', $status);
        }

        $since = isset($filters['since']) && is_string($filters['since']) ? trim($filters['since']) : '';
        if ($since !== '') {
            $q->where('created_at', '>=', $since);
        }

        $until = isset($filters['until']) && is_string($filters['until']) ? trim($filters['until']) : '';
        if ($until !== '') {
            $q->where('created_at', '<=', $until);
        }

        return $q->paginate(perPage: max(1, min(100, $perPage)));
    }

    public function findById(int $id): ?ShopifyWebhookLog
    {
        /** @var ShopifyWebhookLog|null $log */
        $log = ShopifyWebhookLog::query()->find($id);

        return $log;
    }
}
