<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ProductDemandShowRequest extends FormRequest
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
            'lines_page' => ['sometimes', 'integer', 'min:1'],
            'lines_per_page' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ];
    }

    public function linesPage(): int
    {
        return max(1, (int) $this->integer('lines_page', 1));
    }

    public function linesPerPage(): int
    {
        return max(1, min(25, (int) $this->integer('lines_per_page', 10)));
    }
}
