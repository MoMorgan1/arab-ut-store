<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class RefundPaylinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && in_array($user->role, [UserRole::Admin, UserRole::Staff], true);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'amountHalalah' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['amountHalalah', 'reason']) !== []) {
                $validator->errors()->add('request', 'Unknown refund fields are not allowed.');
            }
        }];
    }
}
