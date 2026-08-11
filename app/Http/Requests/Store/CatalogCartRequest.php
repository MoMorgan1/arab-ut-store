<?php

namespace App\Http\Requests\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class CatalogCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variantId' => ['required', 'string', 'ulid'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['variantId']) !== []) {
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
