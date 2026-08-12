<?php

namespace App\Http\Requests\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

final class CartItemCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ea_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'ea_password' => ['present', 'string', 'min:1', 'max:128'],
            'backup_codes' => ['required', 'array', 'size:3'],
            'backup_codes.*' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D', 'distinct:strict'],
            'current_balance' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'companion_market_open' => ['sometimes', 'boolean'],
            'policy_accepted' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), [
                'ea_email',
                'ea_password',
                'backup_codes',
                'current_balance',
                'companion_market_open',
                'policy_accepted',
            ]) !== []) {
                $validator->errors()->add('request', trans('store.cart.unknown_fields'));
            }
        }];
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

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => trans('store.cart.validation_error'),
                'errors' => $validator->errors(),
            ], 422)->header('Cache-Control', 'no-store'),
        );
    }
}
