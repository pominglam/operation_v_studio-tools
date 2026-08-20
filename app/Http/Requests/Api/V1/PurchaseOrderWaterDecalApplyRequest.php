<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderWaterDecalApplyRequest extends FormRequest
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
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.item_id' => ['required', 'integer', 'min:1'],
            'rows.*.sku' => ['required', 'string', 'max:64'],
            'rows.*.description' => ['required', 'string', 'max:512'],
            'rows.*.vendor' => ['required', 'string', 'max:128'],
            'rows.*.type' => ['nullable', 'string', 'max:64'],
            'rows.*.confirm_merge' => ['sometimes', 'boolean'],
        ];
    }
}
