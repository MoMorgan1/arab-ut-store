<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class MoveAdminFaqEntry extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = ['direction'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::MarketingManage->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', 'in:up,down'],
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

    public function direction(): string
    {
        return (string) $this->input('direction');
    }
}
