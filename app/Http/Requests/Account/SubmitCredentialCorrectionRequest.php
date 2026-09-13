<?php

namespace App\Http\Requests\Account;

use App\ValueObjects\EaAccountCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class SubmitCredentialCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ea_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'ea_password' => ['required', 'string', 'min:1', 'max:256'],
            'backup_codes' => ['required', 'array', 'size:3'],
            'backup_codes.*' => [
                'required',
                'string',
                'regex:'.EaAccountCredentials::BACKUP_CODE_PATTERN,
                'distinct:strict',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('ea_email');
        $codes = $this->input('backup_codes');

        $this->merge([
            'ea_email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'backup_codes' => is_array($codes)
                ? array_map(fn (mixed $code): mixed => is_string($code) ? trim($code) : $code, $codes)
                : $codes,
        ]);
    }
}
