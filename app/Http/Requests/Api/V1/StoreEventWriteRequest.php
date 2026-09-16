<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\StoreEvents\StoreEventWriteData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreEventWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cancelled' => ['sometimes', 'boolean'],
        ];
    }

    public function toWriteData(): StoreEventWriteData
    {
        $validated = $this->validated();
        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : '';

        return new StoreEventWriteData(
            name: trim((string) $validated['name']),
            startsOn: (string) $validated['starts_on'],
            endsOn: (string) $validated['ends_on'],
            notes: $notes === '' ? null : $notes,
            cancelled: $this->boolean('cancelled'),
        );
    }
}
