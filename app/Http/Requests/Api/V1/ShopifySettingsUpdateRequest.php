<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ShopifySettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_reconcile_interval_minutes' => [
                'required_without:order_reconcile_interval_hours',
                'integer',
                'min:15',
                'max:10080',
            ],
            'order_reconcile_interval_hours' => [
                'required_without:order_reconcile_interval_minutes',
                'integer',
                'min:1',
                'max:168',
            ],
        ];
    }
}
