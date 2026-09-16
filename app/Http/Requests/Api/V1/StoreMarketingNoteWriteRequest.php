<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\StoreMarketing\StoreMarketingNoteWriteData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMarketingNoteWriteRequest extends FormRequest
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
            'happened_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toWriteData(): StoreMarketingNoteWriteData
    {
        $validated = $this->validated();
        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : '';

        return new StoreMarketingNoteWriteData(
            name: trim((string) $validated['name']),
            happenedOn: (string) $validated['happened_on'],
            notes: $notes === '' ? null : $notes,
        );
    }
}
