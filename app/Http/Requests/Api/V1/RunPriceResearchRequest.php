<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RunPriceResearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $allowedSiteKeys */
        $allowedSiteKeys = array_keys((array) config('price_research.sites', []));
        /** @var array<int, string> $disabled */
        $disabled = (array) config('price_research.disabled_site_keys', []);
        $disabled = array_values(array_unique(array_filter(array_map('trim', $disabled), static fn (string $v): bool => $v !== '')));
        $allowedSiteKeys = array_values(array_diff($allowedSiteKeys, $disabled));

        return [
            'ids' => ['sometimes', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
            'force' => ['sometimes', 'boolean'],
            'site_keys' => ['sometimes', 'array', 'min:1'],
            'site_keys.*' => ['required', 'string', Rule::in($allowedSiteKeys), 'distinct'],

            // Optional filters (used by Maintenance "recrawl by site" to target subsets).
            'status' => ['sometimes', 'string', Rule::in(['any', 'fresh', 'expired'])],
            'quote_status' => ['sometimes', 'string', Rule::in(['any', 'found', 'not_found', 'error'])],
            'types' => ['sometimes', 'array'],
            'types.*' => ['required', 'string'],
            'vendors' => ['sometimes', 'array'],
            'vendors.*' => ['required', 'string'],
        ];
    }
}
