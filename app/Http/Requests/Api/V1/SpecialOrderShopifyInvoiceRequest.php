<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SpecialOrderShopifyInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'line_title' => ['nullable', 'string', 'max:255'],
            'shopify_customer_gid' => ['nullable', 'string', 'max:191'],
            'send_invoice' => ['sometimes', 'boolean'],
            'customer_email' => ['nullable', 'string', 'email', 'max:255'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
