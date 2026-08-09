<?php

namespace App\Http\Requests\Store;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CoinsQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $platform = $this->input('platform');
        $delivery = $this->input('delivery');
        $isPc = $platform === Platform::Pc->value;
        $maximum = match (true) {
            $isPc => config('coins.platforms.pc.maximum'),
            $delivery === DeliveryMode::Fast->value => config('coins.platforms.playstation.deliveries.fast.maximum'),
            default => config('coins.platforms.playstation.deliveries.normal.maximum'),
        };

        return [
            'platform' => [
                'required',
                Rule::enum(Platform::class)->only([Platform::PlayStation, Platform::Pc]),
            ],
            'delivery' => $isPc
                ? ['missing']
                : ['required', Rule::enum(DeliveryMode::class)],
            'quantity' => [
                'required',
                'integer',
                'min:'.config('coins.quantity.minimum'),
                'max:'.$maximum,
                'multiple_of:'.config('coins.quantity.increment'),
            ],
        ];
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
