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

            // Carried as headers rather than body fields because this request
            // rejects a non-empty body outright, and the idempotency key already
            // establishes that idiom.
            foreach (['X-Expected-Total-Halalah', 'X-Expected-Order-Total-Halalah'] as $header) {
                $expected = $this->header($header);

                if (! is_string($expected) || preg_match('/\A[0-9]{1,18}\z/D', $expected) !== 1) {
                    $validator->errors()->add('expected_total', trans('store.checkout.expected_total'));
                }
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    /**
     * The cash payable the cart showed, after the wallet deduction.
     *
     * Both expected totals are now mandatory. Skipping the check on an absent
     * header would charge a customer a price they were never shown, which is
     * exactly what this pair exists to prevent.
     */
    public function expectedPayableHalalah(): int
    {
        return (int) $this->header('X-Expected-Total-Halalah');
    }

    /** The order total the cart showed, before the wallet deduction. */
    public function expectedOrderTotalHalalah(): int
    {
        return (int) $this->header('X-Expected-Order-Total-Halalah');
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
