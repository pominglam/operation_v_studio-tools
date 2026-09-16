<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Shopify\Admin\Orders\ShopifyOrderIndexFilters;
use App\Support\Shopify\Admin\Orders\ShopifyOrderIndexSort;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShopifyOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
            'channel' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(['all', 'eligible', 'cancelled'])],
            'preorder' => ['sometimes', Rule::in(['all', 'only'])],
            'sort_by' => ['sometimes', Rule::in(ShopifyOrderIndexSort::ALLOWED)],
            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): ShopifyOrderIndexFilters
    {
        $validated = $this->validated();
        $timezone = (string) config('shopify.staff_order_report.timezone', 'America/Toronto');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $defaultFrom = CarbonImmutable::now($timezone)->subDays(6)->toDateString();

        $from = is_string($validated['from'] ?? null) ? $validated['from'] : $defaultFrom;
        $until = is_string($validated['until'] ?? null) ? $validated['until'] : $today;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $channel = isset($validated['channel']) ? trim((string) $validated['channel']) : '';

        return new ShopifyOrderIndexFilters(
            fromDate: $from,
            untilDate: $until,
            search: $search !== '' ? $search : null,
            channel: $channel !== '' ? $channel : null,
            status: is_string($validated['status'] ?? null) ? $validated['status'] : 'all',
            preorder: is_string($validated['preorder'] ?? null) ? $validated['preorder'] : 'all',
            sortBy: ShopifyOrderIndexSort::normalize((string) ($validated['sort_by'] ?? ShopifyOrderIndexSort::DEFAULT)),
            sortDir: ShopifyOrderIndexSort::normalizeDir((string) ($validated['sort_dir'] ?? 'desc')),
            perPage: max(1, min((int) ($validated['per_page'] ?? 50), 100)),
        );
    }
}
