<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateAdminPromotion extends FormRequest
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
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'badge_ar' => ['sometimes', 'nullable', 'string', 'max:24'],
            'badge_en' => ['sometimes', 'nullable', 'string', 'max:24'],
            'scope' => ['required', 'string', Rule::in(['all', 'category', 'service'])],
            'category' => [
                'nullable',
                'string',
                'required_if:scope,category',
                Rule::exists('categories', 'public_id'),
                Rule::prohibitedIf($this->input('scope') !== 'category'),
            ],
            'service_type' => [
                'nullable',
                'string',
                'required_if:scope,service',
                Rule::in(array_map(fn (ServiceType $type): string => $type->value, ServiceType::cases())),
                Rule::prohibitedIf($this->input('scope') !== 'service'),
            ],
            'discount_type' => ['required', 'string', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1'],
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

            if ($type === 'percent' && is_numeric($value) && ((int) $value < 1 || (int) $value > 90)) {
                $validator->errors()->add('value', 'The discount percentage must be between 1 and 90.');
            }

            if ($type === 'fixed' && is_numeric($value) && (int) $value < 100) {
                $validator->errors()->add('value', 'The fixed discount must be at least 100 halalah.');
            }
        });
    }
}
