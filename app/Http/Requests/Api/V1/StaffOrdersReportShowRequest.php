<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StaffOrdersReportShowRequest extends FormRequest
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
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    public function month(): string
    {
        return (string) $this->validated('month');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $month = (string) $this->input('month', '');
            if ($month === '' || $validator->errors()->has('month')) {
                return;
            }

            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $month.'-01');
            if ($parsed === false || $parsed->format('Y-m') !== $month) {
                $validator->errors()->add('month', 'Month must be a valid YYYY-MM value.');
            }
        });
    }
}
