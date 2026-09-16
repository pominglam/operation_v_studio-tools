<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodInstockSyncRetryRequest extends FormRequest
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
            'filters' => ['required', 'array', 'min:1', 'max:80'],
            'filters.*.name' => ['required', 'string', 'max:180'],
            'filters.*.tab' => ['nullable', 'string', 'max:40'],
            'filters.*.category_id' => ['nullable', 'string', 'max:64'],
            'filters.*.expected' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<int, array{name: string, tab: string, category_id: string|null, expected: int}>
     */
    public function filters(): array
    {
        /** @var array<int, array<string, mixed>> $filters */
        $filters = $this->validated('filters');
        $normalized = [];
        foreach ($filters as $filter) {
            $name = trim((string) ($filter['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'tab' => trim((string) ($filter['tab'] ?? 'BRAND')) ?: 'BRAND',
                'category_id' => self::nullableString($filter['category_id'] ?? null),
                'expected' => (int) ($filter['expected'] ?? 0),
            ];
        }

        return $normalized;
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
