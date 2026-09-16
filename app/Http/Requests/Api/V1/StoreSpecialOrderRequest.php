<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\SpecialOrders\SpecialOrderContactMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSpecialOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_contact_media' => ['required', 'string', Rule::in(SpecialOrderContactMedia::ALL)],
            'customer_contact_value' => ['required', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
