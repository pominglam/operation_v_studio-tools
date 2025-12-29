<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DatabaseBackupCreateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'string', 'max:500'],
            'created_by' => ['sometimes', 'string', Rule::in(['manual', 'system', 'cursor'])],
        ];
    }
}


