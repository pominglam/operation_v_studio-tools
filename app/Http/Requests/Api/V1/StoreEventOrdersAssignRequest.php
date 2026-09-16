<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEventOrdersAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'distinct'],
            'included' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<int>
     */
    public function orderIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->validated('order_ids'));

        return $ids;
    }
}
