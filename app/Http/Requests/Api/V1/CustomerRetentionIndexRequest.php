<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Customers\CustomerCadenceStatus;
use App\Support\Customers\CustomerChurnStatus;
use App\Support\Customers\CustomerFrequencyLabel;
use App\Support\Customers\CustomerRetentionIndexFilters;
use App\Support\Customers\CustomerRetentionIndexSort;
use App\Support\Customers\ShopifyRfmGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerRetentionIndexRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
            'frequency' => ['sometimes', 'nullable', Rule::in(CustomerFrequencyLabel::allowedFilters())],
            'rfm_group' => ['sometimes', 'nullable', Rule::in(ShopifyRfmGroup::keys())],
            'churn' => ['sometimes', 'nullable', Rule::in(CustomerChurnStatus::allowedFilters())],
            'cadence' => ['sometimes', 'nullable', Rule::in(CustomerCadenceStatus::allowedFilters())],
            'sort_by' => ['sometimes', Rule::in(CustomerRetentionIndexSort::ALLOWED)],
            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): CustomerRetentionIndexFilters
    {
        $validated = $this->validated();
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $frequency = isset($validated['frequency']) ? trim((string) $validated['frequency']) : '';
        $rfmGroup = isset($validated['rfm_group']) ? trim((string) $validated['rfm_group']) : '';
        $churn = isset($validated['churn']) ? trim((string) $validated['churn']) : '';
        $cadence = isset($validated['cadence']) ? trim((string) $validated['cadence']) : '';

        return new CustomerRetentionIndexFilters(
            search: $search !== '' ? $search : null,
            frequency: $frequency !== '' ? $frequency : null,
            rfmGroup: $rfmGroup !== '' ? $rfmGroup : null,
            churn: $churn !== '' ? $churn : null,
            cadence: $cadence !== '' ? $cadence : null,
            sortBy: CustomerRetentionIndexSort::normalize((string) ($validated['sort_by'] ?? CustomerRetentionIndexSort::DEFAULT)),
            sortDir: CustomerRetentionIndexSort::normalizeDir((string) ($validated['sort_dir'] ?? 'desc')),
            page: max(1, (int) ($validated['page'] ?? 1)),
            perPage: max(1, min((int) ($validated['per_page'] ?? 50), 100)),
        );
    }
}
