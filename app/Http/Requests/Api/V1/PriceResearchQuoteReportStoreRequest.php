<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PriceResearchQuoteReportStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'site_key' => ['required', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'run_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
