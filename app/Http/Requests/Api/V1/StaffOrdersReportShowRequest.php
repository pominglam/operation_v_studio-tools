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
            'month' => ['sometimes', 'regex:/^\d{4}-\d{2}$/'],
            'from_month' => ['sometimes', 'regex:/^\d{4}-\d{2}$/'],
            'to_month' => ['sometimes', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function resolvedRange(): array
    {
        $validated = $this->validated();
        $month = is_string($validated['month'] ?? null) ? trim($validated['month']) : '';
        if ($month !== '') {
            return [$month, $month];
        }

        return [
            (string) $validated['from_month'],
            (string) $validated['to_month'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $month = trim((string) $this->input('month', ''));
            $fromMonth = trim((string) $this->input('from_month', ''));
            $toMonth = trim((string) $this->input('to_month', ''));

            if ($month !== '' && ! $validator->errors()->has('month')) {
                $this->assertValidMonth($validator, 'month', $month);

                return;
            }

            if ($fromMonth === '' || $toMonth === '') {
                $validator->errors()->add('from_month', 'Provide month or both from_month and to_month.');

                return;
            }

            if ($validator->errors()->hasAny(['from_month', 'to_month'])) {
                return;
            }

            $this->assertValidMonth($validator, 'from_month', $fromMonth);
            $this->assertValidMonth($validator, 'to_month', $toMonth);

            if ($fromMonth > $toMonth) {
                $validator->errors()->add('to_month', 'to_month must be on or after from_month.');
            }
        });
    }

    private function assertValidMonth(Validator $validator, string $field, string $month): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $month.'-01');
        if ($parsed === false || $parsed->format('Y-m') !== $month) {
            $validator->errors()->add($field, 'Month must be a valid YYYY-MM value.');
        }
    }
}
