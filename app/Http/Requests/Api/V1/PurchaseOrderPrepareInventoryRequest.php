<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderPrepareInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'pull_shopify' => ['sometimes', 'boolean'],
        ];
    }

    public function pullShopify(): bool
    {
        return (bool) $this->boolean('pull_shopify');
    }
}
