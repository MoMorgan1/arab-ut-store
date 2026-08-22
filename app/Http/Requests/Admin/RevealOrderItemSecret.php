<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class RevealOrderItemSecret extends FormRequest
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
            'purpose' => [
                'sometimes',
                'string',
                Rule::in([
                    'fulfillment',
                    'customer_support',
                    'order_review',
                    'incident_investigation',
                ]),
            ],
            'case_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._:-]{1,64}$/',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = ['purpose', 'case_reference'];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }
        });
    }

    public function purpose(): string
    {
        $value = $this->input('purpose');

        return is_string($value) && $value !== '' ? $value : 'fulfillment';
    }

    public function caseReference(): ?string
    {
        $value = $this->input('case_reference');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
