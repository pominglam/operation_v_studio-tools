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

        return [
            'ids' => ['sometimes', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
            'force' => ['sometimes', 'boolean'],
            'site_keys' => ['sometimes', 'array', 'min:1'],
            'site_keys.*' => ['required', 'string', Rule::in($allowedSiteKeys), 'distinct'],
        ];
    }
}
