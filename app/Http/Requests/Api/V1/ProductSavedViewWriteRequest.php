<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Products\ProductSavedViewWriteData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ProductSavedViewWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'snapshot' => ['required', 'array'],
            'visible_columns' => ['required', 'array', 'max:40'],
            'visible_columns.*' => ['string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->normalizedName() === '') {
                $validator->errors()->add('name', 'Name is required.');
            }
        });
    }

    public function toWriteData(): ProductSavedViewWriteData
    {
        $validated = $this->validated();
        /** @var array<string, mixed> $snapshot */
        $snapshot = $validated['snapshot'];
        /** @var list<string> $columns */
        $columns = array_values(array_map(
            static fn (mixed $column): string => (string) $column,
            $validated['visible_columns'],
        ));

        return new ProductSavedViewWriteData(
            name: $this->normalizedName(),
            snapshot: $snapshot,
            visibleColumns: $columns,
        );
    }

    private function normalizedName(): string
    {
        $collapsed = preg_replace('/\s+/', ' ', (string) $this->input('name', ''));

        return trim(is_string($collapsed) ? $collapsed : '');
    }
}
