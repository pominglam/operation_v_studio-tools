<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodRestockCartRunCreateRequest extends FormRequest
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
            'skus' => ['required', 'array', 'min:1', 'max:500'],
            'skus.*' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function skus(): array
    {
        /** @var array<int, string> $skus */
        $skus = $this->validated('skus');

        return array_values(array_unique(array_map(static fn (string $sku): string => trim($sku), $skus)));
    }
}
