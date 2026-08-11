<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyWhatsAppCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', 'regex:/\A\+[1-9][0-9]{7,14}\z/D'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
