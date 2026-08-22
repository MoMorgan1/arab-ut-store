<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class TransitionAdminOrder extends FormRequest
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
            'target_status' => [
                'required',
                'string',
                Rule::in(array_map(
                    fn (OrderStatus $status): string => $status->value,
                    array_filter(
                        OrderStatus::cases(),
                        fn (OrderStatus $status): bool => $status !== OrderStatus::Refunded,
                    ),
                )),
            ],
            'expected_status' => [
                'required',
                'string',
                Rule::enum(OrderStatus::class),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = ['target_status', 'expected_status'];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }
        });
    }

    public function targetStatus(): OrderStatus
    {
        return OrderStatus::from((string) $this->input('target_status'));
    }

    public function expectedStatus(): OrderStatus
    {
        return OrderStatus::from((string) $this->input('expected_status'));
    }
}
