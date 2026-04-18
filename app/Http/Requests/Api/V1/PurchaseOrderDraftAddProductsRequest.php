<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderDraftAddProductsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'skus' => ['required', 'array', 'min:1', 'max:1000'],
            'skus.*' => ['string', 'max:120'],
        ];
    }
}
