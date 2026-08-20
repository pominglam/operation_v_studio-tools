<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderWaterDecalPreviewRequest extends FormRequest
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
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'min:1'],
            'proposed' => ['sometimes', 'array'],
            'proposed.*.item_id' => ['required', 'integer', 'min:1'],
            'proposed.*.proposed_sku' => ['required', 'string', 'max:64'],
        ];
    }
}
