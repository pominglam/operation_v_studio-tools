<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\CustomOrders\CustomAsiaOrderContactMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomAsiaOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_contact_media' => ['required', 'string', Rule::in(CustomAsiaOrderContactMedia::ALL)],
            'customer_contact_value' => ['required', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
