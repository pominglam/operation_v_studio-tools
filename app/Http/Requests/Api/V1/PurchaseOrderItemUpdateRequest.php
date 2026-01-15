<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderItemUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qty_ordered' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'qty_shipped' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'qty_received' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator): void {
                if (! $this->has('qty_ordered') && ! $this->has('qty_shipped') && ! $this->has('qty_received')) {
                    $validator->errors()->add('qty_ordered', 'Provide qty_ordered, qty_shipped, or qty_received.');
                }
            },
        ];
    }
}

