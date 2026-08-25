<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderHoldReason;
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
            'reason' => [
                'nullable',
                'string',
                Rule::enum(OrderHoldReason::class),
            ],
            'note' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = ['target_status', 'expected_status', 'reason', 'note'];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }

            // A stopped order the customer cannot explain is the whole defect
            // this field exists to close, so pausing without a reason is refused.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ((string) $this->input('target_status') !== OrderStatus::WaitingForCustomer->value) {
                return;
            }

            if ($this->reason() === null && $this->note() === null) {
                $validator->errors()->add('reason', 'Pausing an order requires a reason the customer can read.');
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

    public function reason(): ?OrderHoldReason
    {
        $reason = $this->input('reason');

        return is_string($reason) && $reason !== ''
            ? OrderHoldReason::from($reason)
            : null;
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        if (! is_string($note)) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : $trimmed;
    }
}
