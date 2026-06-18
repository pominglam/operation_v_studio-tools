<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ProductsBulkPushShopifySelectedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['string', 'uuid'],
            'push_options' => ['required', 'array'],
            'push_options.info' => ['sometimes', 'boolean'],
            'push_options.images' => ['sometimes', 'boolean'],
            'push_options.quantities' => ['sometimes', 'boolean'],
            'push_options.price' => ['sometimes', 'boolean'],
            'push_options.publish_status' => ['sometimes', 'boolean'],
            'push_options.sales_channels' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<string, mixed>|null $options */
            $options = $this->input('push_options');
            if (! is_array($options)) {
                return;
            }

            $any = false;
            foreach (['info', 'images', 'quantities', 'price', 'publish_status', 'sales_channels'] as $key) {
                if (filter_var($options[$key] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $any = true;
                    break;
                }
            }

            if (! $any) {
                $validator->errors()->add('push_options', 'Select at least one field to push.');
            }
        });
    }
}
