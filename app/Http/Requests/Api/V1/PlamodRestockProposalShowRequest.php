<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PlamodRestockProposalShowRequest extends FormRequest
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
            'hide_dismissed' => ['sometimes', 'boolean'],
            'only_included_new' => ['sometimes', 'boolean'],
            'section' => ['sometimes', 'string', 'in:all,existing,new'],
        ];
    }

    public function section(): string
    {
        $section = $this->validated('section', 'all');

        return is_string($section) ? $section : 'all';
    }
}
