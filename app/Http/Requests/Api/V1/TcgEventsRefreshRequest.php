<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class TcgEventsRefreshRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'string', 'date_format:Y-m-d'],
            'street_address' => ['sometimes', 'string', 'max:200'],
            'country_code' => ['sometimes', 'string', 'max:4'],
            'pref_code' => ['sometimes', 'string', 'max:16'],
            'game_title_id' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
