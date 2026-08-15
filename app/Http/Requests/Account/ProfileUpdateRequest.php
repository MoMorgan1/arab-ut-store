<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProfileUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'preferred_locale' => ['required', 'string', Rule::in(config('store.locales'))],
            'display_currency' => ['required', 'string', Rule::in(config('store.display_currencies'))],
        ];
    }
}
