<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreAdminCoupon extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::MarketingManage->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'min:3',
                'max:24',
                'regex:/\A[A-Z0-9\-]{3,24}\z/D',
                Rule::unique('coupons', 'code'),
            ],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'string', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1'],
            'minimum_order_halalah' => ['required', 'integer', 'min:0'],
            'maximum_discount_halalah' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('discount_type');
            $value = $this->input('value');

            if ($type === 'percent' && is_numeric($value) && ((int) $value < 1 || (int) $value > 100)) {
                $validator->errors()->add('value', 'The discount percentage must be between 1 and 100.');
            }

            if ($type === 'fixed' && is_numeric($value) && (int) $value < 100) {
                $validator->errors()->add('value', 'The fixed discount must be at least 100 halalah.');
            }

            if ($type !== 'percent' && ! empty($this->input('maximum_discount_halalah'))) {
                $validator->errors()->add('maximum_discount_halalah', 'Maximum discount is only applicable for percent coupons.');
            }
        });
    }
}
