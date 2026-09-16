<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodPreordersIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'new_only' => ['sometimes', 'boolean'],
            'store_offer' => ['sometimes', 'string', 'in:all,not_opened,opened'],
            'interest' => ['sometimes', 'string', 'in:interested,not_interested,all'],
            'sort' => ['sometimes', 'string', 'in:name,release,category,stock,sell,qty,closing,eta,eta_months'],
            'sort_dir' => ['sometimes', 'string', 'in:asc,desc'],
            'include_closed' => ['sometimes', 'boolean'],
            'future_releases_only' => ['sometimes', 'boolean'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'max:128'],
        ];
    }
}
