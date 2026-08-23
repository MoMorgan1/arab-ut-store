<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AdjustAdminCustomerWallet extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::WalletAdjust->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_halalah' => [
                'required',
                'integer',
                'not_in:0',
                'min:-100000',
                'max:100000',
            ],
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:200',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = ['amount_halalah', 'reason'];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }
        });
    }

    public function amountHalalah(): int
    {
        return (int) $this->input('amount_halalah');
    }

    public function reason(): string
    {
        return (string) $this->input('reason');
    }
}
