<?php

namespace App\Http\Requests\Store;

use App\Validation\CoinsSelectionRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CoinsQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(CoinsSelectionRules::class)->for($this->input('platform'), $this->input('delivery'));
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowedFields = ['platform', 'delivery', 'quantity'];

            foreach (array_diff(array_keys($this->all()), $allowedFields) as $field) {
                $validator->errors()->add(
                    $field,
                    "The {$field} field is prohibited.",
                );
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => trans('store.quote.validation_error'),
                'errors' => $validator->errors(),
            ], 422)->header('Cache-Control', 'no-store'),
        );
    }
}
