<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\PlamodRestockSkuDecisionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlamodRestockDecisionUpsertRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in([
                PlamodRestockSkuDecisionStatus::Dismissed->value,
                PlamodRestockSkuDecisionStatus::Included->value,
                PlamodRestockSkuDecisionStatus::Later->value,
            ])],
            'order_qty' => ['nullable', 'integer', 'min:0'],
            'planned_maintain_qty' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function decisionStatus(): PlamodRestockSkuDecisionStatus
    {
        return PlamodRestockSkuDecisionStatus::from((string) $this->validated('status'));
    }
}
