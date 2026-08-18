<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PurchaseOrderItemsBulkUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Back-compat (previous bulk UI)
            'qty_shipped_all' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'set_all_to_ordered' => ['sometimes', 'boolean'],
            // New "Products-style" bulk UI
            'ids' => ['sometimes', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'changes' => ['sometimes', 'array'],
            'changes.qty_shipped' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.qty_received' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'changes.set_shipped_to_ordered' => ['sometimes', 'boolean'],
            'changes.set_received_to_shipped' => ['sometimes', 'boolean'],
            'changes.product_vendor' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasAll = $this->has('qty_shipped_all');
            $setAllToOrdered = (bool) $this->input('set_all_to_ordered', false);
            $ids = $this->input('ids');
            $hasIds = is_array($ids) && $ids !== [];
            $changes = $this->input('changes');
            $hasChanges = is_array($changes) && $changes !== [];

            if (! $hasAll && ! $setAllToOrdered && ! ($hasIds && $hasChanges)) {
                $v->errors()->add('changes', 'Provide qty_shipped_all, set_all_to_ordered, or ids[] + changes.');
            }
        });
    }
}
