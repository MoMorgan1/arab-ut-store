<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SetAdminFaqEntryVisibility extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = ['visible', 'expectedVisible'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::MarketingManage->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'visible' => ['required', 'boolean'],
            'expectedVisible' => ['required', 'boolean'],
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

    public function visible(): bool
    {
        return $this->boolean('visible');
    }

    public function expectedVisible(): bool
    {
        return $this->boolean('expectedVisible');
    }
}
