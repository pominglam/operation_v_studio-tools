<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use App\Support\CustomOrders\CustomAsiaOrderLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomAsiaOrderIndexRequest extends FormRequest
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
            'lifecycle_status' => ['nullable', 'string', Rule::in(CustomAsiaOrderLifecycleStatus::ALL_VALUES)],
            'contact_media' => ['nullable', 'array', 'max:10'],
            'contact_media.*' => ['string', Rule::in(CustomAsiaOrderContactMedia::ALL)],
        ];
    }
}
