<?php

namespace App\Http\Requests\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PaylinkCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->all() !== []) {
                $validator->errors()->add('request', trans('store.checkout.unknown_fields'));
            }

            $key = $this->header('Idempotency-Key');

            if (! is_string($key) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1) {
                $validator->errors()->add('idempotency_key', trans('store.checkout.idempotency_key'));
            }

            // Carried as a header rather than a body field because this request
            // rejects a non-empty body outright, and the idempotency key already
            // establishes that idiom.
            $expected = $this->header('X-Expected-Total-Halalah');

            if ($expected !== null && preg_match('/\A[0-9]{1,18}\z/D', (string) $expected) !== 1) {
                $validator->errors()->add('expected_total', trans('store.checkout.expected_total'));
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    /**
     * The payable total the cart showed, when the client sent one.
     *
     * Null means "not supplied" and skips the check, so an older client still
     * checks out; the storefront always sends it.
     */
    public function expectedPayableHalalah(): ?int
    {
        $expected = $this->header('X-Expected-Total-Halalah');

        return $expected === null ? null : (int) $expected;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'error' => [
                    'code' => 'checkout_validation_error',
                    'message' => trans('store.checkout.validation_error'),
                ],
                'errors' => $validator->errors(),
            ], 422)->header('Cache-Control', 'no-store, private'),
        );
    }
}
