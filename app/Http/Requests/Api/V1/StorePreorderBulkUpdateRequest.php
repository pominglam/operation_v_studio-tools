<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StorePreorderBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $changes = $this->input('changes');
        if (! is_array($changes) || ! array_key_exists('cap_qty', $changes)) {
            return;
        }
        $raw = $changes['cap_qty'];
        if ($raw === '' || $raw === '0' || $raw === 0) {
            $changes['cap_qty'] = null;
            $this->merge(['changes' => $changes]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'uuid'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.cap_qty' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'changes.deposit_percent' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'changes.selling_price' => ['sometimes', 'numeric', 'min:0.01', 'max:99999.99'],
            'changes.window_ends_on' => ['sometimes', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $changes = $this->input('changes');
            if (! is_array($changes)) {
                return;
            }
            $keys = ['cap_qty', 'deposit_percent', 'selling_price', 'window_ends_on'];
            foreach ($keys as $key) {
                if (array_key_exists($key, $changes)) {
                    return;
                }
            }
            $validator->errors()->add('changes', 'Select at least one field to apply.');
        });
    }
}
