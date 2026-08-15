<?php

namespace App\Http\Requests\Account;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

final class PasswordSetupRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => $this->passwordRules()];
    }
}
