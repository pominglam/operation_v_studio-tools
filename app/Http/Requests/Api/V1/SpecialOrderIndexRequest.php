<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\SpecialOrders\SpecialOrderContactMedia;
use App\Support\SpecialOrders\SpecialOrderLifecycleStatus;
use App\Support\SpecialOrders\SpecialOrderWorkflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SpecialOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', 'max:32'],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'quote_status' => ['nullable', 'string', Rule::in(['pending', 'quoted'])],
            'pricing_status' => ['nullable', 'string', Rule::in(['pending', 'priced'])],
            'lifecycle_status' => ['nullable', 'string', Rule::in(SpecialOrderLifecycleStatus::ALL_VALUES)],
            'workflow_status' => ['nullable', 'array', 'max:20'],
            'workflow_status.*' => ['string', Rule::in(SpecialOrderWorkflowStatus::ALL_VALUES)],
            'contact_media' => ['nullable', 'array', 'max:10'],
            'contact_media.*' => ['string', Rule::in(SpecialOrderContactMedia::ALL)],
        ];
    }
}
