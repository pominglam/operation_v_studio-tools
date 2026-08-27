<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CustomAsiaOrderCustomerMessageTemplateUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required_without:reset', 'nullable', 'string', 'max:20000'],
            'reset' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('reset')) {
                return;
            }

            $body = $this->input('body');
            if (! is_string($body) || trim($body) === '') {
                $validator->errors()->add('body', 'Template body is required.');

                return;
            }

            try {
                \App\Support\CustomOrders\CustomAsiaOrderCustomerMessageTemplate::assertPlaceholders(trim($body));
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('body', $exception->getMessage());
            }
        });
    }
}
