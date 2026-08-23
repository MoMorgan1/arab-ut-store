<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAdminCustomerContact extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CustomersUpdateContact->value);
    }

    /**
     * Normalized before validation so the uniqueness rule and the stored value
     * agree: MySQL compares email case-insensitively but SQLite does not, so a
     * case variant would otherwise pass validation and then hit the unique
     * index.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $publicId = (string) $this->route('publicId');
        $targetId = User::query()->where('public_id', $publicId)->value('id');

        return [
            'first_name' => [
                'required',
                'string',
                'max:255',
            ],
            'last_name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/\A\+[1-9][0-9]{7,14}\z/D',
                Rule::unique('users', 'phone')->ignore($targetId),
            ],
            'expected' => ['required', 'array:first_name,last_name,email,phone'],
            'expected.first_name' => ['required', 'string', 'max:255'],
            'expected.last_name' => ['required', 'string', 'max:255'],
            'expected.email' => ['required', 'string', 'max:255'],
            'expected.phone' => ['present', 'nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = ['first_name', 'last_name', 'email', 'phone', 'expected'];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }
        });
    }

    public function firstName(): string
    {
        return (string) $this->input('first_name');
    }

    public function lastName(): string
    {
        return (string) $this->input('last_name');
    }

    public function email(): string
    {
        return (string) $this->input('email');
    }

    public function phone(): ?string
    {
        $value = $this->input('phone');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, phone: string|null}
     */
    public function expected(): array
    {
        $phone = $this->input('expected.phone');

        return [
            'first_name' => (string) $this->input('expected.first_name'),
            'last_name' => (string) $this->input('expected.last_name'),
            'email' => (string) $this->input('expected.email'),
            'phone' => is_string($phone) && $phone !== '' ? $phone : null,
        ];
    }
}
