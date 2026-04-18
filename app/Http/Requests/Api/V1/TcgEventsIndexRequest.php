<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class TcgEventsIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'start_date' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'string', 'max:50'],
            'format' => ['sometimes', 'string', 'max:128'],
            'hide_zero_applicants' => ['sometimes', 'boolean'],
        ];
    }
}
