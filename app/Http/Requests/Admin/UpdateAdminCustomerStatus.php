<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAdminCustomerStatus extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CustomersUpdateStatus->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(['suspend', 'reactivate']),
            ],
            'reason_code' => [
                'required',
                'string',
                Rule::in([
                    'fraud_suspected',
                    'chargeback',
                    'abuse',
                    'customer_request',
                    'account_recovery',
                    'other_reviewed',
                ]),
            ],
            'case_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._:-]{1,64}$/',
            ],
            'expected_active' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = ['action', 'reason_code', 'case_reference', 'expected_active'];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }
        });
    }

    public function action(): string
    {
        return (string) $this->input('action');
    }

    public function reasonCode(): string
    {
        return (string) $this->input('reason_code');
    }

    public function caseReference(): ?string
    {
        $value = $this->input('case_reference');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function expectedActive(): bool
    {
        return $this->boolean('expected_active');
    }
}
