<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::StaffManage->value);
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
                Rule::in(['activate', 'deactivate']),
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
            $allowedKeys = ['action', 'expected_active'];
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

    public function expectedActive(): bool
    {
        return $this->boolean('expected_active');
    }
}
