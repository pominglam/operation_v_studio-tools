<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\CustomOrders\CustomAsiaOrderCompetitorPriceSites;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomAsiaOrderCompetitorPricesRefreshRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', Rule::in([
                CustomAsiaOrderCompetitorPriceSites::SCOPE_FAST,
                CustomAsiaOrderCompetitorPriceSites::SCOPE_FULL,
            ])],
        ];
    }
}
