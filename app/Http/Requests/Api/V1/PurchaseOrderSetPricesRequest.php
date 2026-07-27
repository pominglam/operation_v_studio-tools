<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderSetPricesRequest extends FormRequest
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
            'overrides' => ['sometimes', 'array', 'max:500'],
            'overrides.*.product_uuid' => ['required_with:overrides', 'uuid'],
            'overrides.*.price' => ['required_with:overrides', 'numeric', 'min:0', 'max:99999.99'],
        ];
    }

    /**
     * @return array<int, array{product_uuid: string, price: string}>
     */
    public function priceOverrides(): array
    {
        $overrides = $this->validated('overrides', []);
        if (! is_array($overrides)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $override): ?array {
                if (! is_array($override)) {
                    return null;
                }

                $productUuid = $override['product_uuid'] ?? null;
                $price = $override['price'] ?? null;
                if (! is_string($productUuid) || (! is_string($price) && ! is_numeric($price))) {
                    return null;
                }

                return [
                    'product_uuid' => $productUuid,
                    'price' => (string) $price,
                ];
            },
            $overrides,
        )));
    }
}
