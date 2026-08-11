<?php

namespace App\Http\Requests\Store;

use App\Validation\CoinsSelectionRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

final class CoinsCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...app(CoinsSelectionRules::class)->for($this->input('platform'), $this->input('delivery')),
            'credentials' => ['required', 'array:ea_email,ea_password,backup_codes'],
            'credentials.ea_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'credentials.ea_password' => ['present', 'string', 'min:1', 'max:128'],
            'credentials.backup_codes' => ['required', 'array', 'size:3'],
            'credentials.backup_codes.*' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D', 'distinct:strict'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['platform', 'delivery', 'quantity', 'credentials']) !== []) {
                $validator->errors()->add('request', trans('store.cart.unknown_fields'));
            }

            $idempotencyKey = $this->header('Idempotency-Key');

            if (! is_string($idempotencyKey)
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $idempotencyKey) !== 1) {
                $validator->errors()->add('idempotency_key', trans('store.cart.idempotency_key'));
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    protected function prepareForValidation(): void
    {
        $credentials = $this->input('credentials');

        if (! is_array($credentials)) {
            return;
        }

        if (is_string($credentials['ea_email'] ?? null)) {
            $credentials['ea_email'] = Str::lower(trim($credentials['ea_email']));
        }

        if (is_array($credentials['backup_codes'] ?? null)) {
            $credentials['backup_codes'] = array_map(
                fn (mixed $code): mixed => is_string($code) ? trim($code) : $code,
                $credentials['backup_codes'],
            );
        }

        $this->merge(['credentials' => $credentials]);
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
