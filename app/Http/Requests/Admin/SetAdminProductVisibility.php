<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SetAdminProductVisibility extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = ['hidden', 'expected_hidden'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CatalogManage->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hidden' => ['required', 'boolean'],
            'expected_hidden' => ['required', 'boolean'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknown = array_diff(array_keys($this->all()), self::ALLOWED_KEYS);

                if ($unknown !== []) {
                    $validator->errors()->add('payload', 'Unknown fields are not allowed.');
                }
            },
        ];
    }

    public function hidden(): bool
    {
        return $this->boolean('hidden');
    }

    public function expectedHidden(): bool
    {
        return $this->boolean('expected_hidden');
    }
}
