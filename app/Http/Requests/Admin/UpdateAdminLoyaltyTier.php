<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateAdminLoyaltyTier extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::LoyaltyManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_ar' => [
                'required',
                'string',
                'min:2',
                'max:40',
            ],
            'name_en' => [
                'required',
                'string',
                'min:2',
                'max:40',
            ],
            'minimum_lifetime_spend_halalah' => [
                'required',
                'integer',
                'min:0',
            ],
            'cashback_basis_points' => [
                'required',
                'integer',
                'min:0',
                'max:2000',
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = [
                'name_ar',
                'name_en',
                'minimum_lifetime_spend_halalah',
                'cashback_basis_points',
                'is_active',
            ];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
            }

            $publicId = (string) $this->route('publicId');
            /** @var LoyaltyTier|null $tier */
            $tier = LoyaltyTier::query()->where('public_id', $publicId)->first();

            if (! $tier instanceof LoyaltyTier) {
                return;
            }

            $minimumSpend = $this->input('minimum_lifetime_spend_halalah');
            if ($minimumSpend === null || ! is_numeric($minimumSpend)) {
                return;
            }

            $minHalalah = (int) $minimumSpend;

            if ($tier->rank === 1 && $minHalalah !== 0) {
                $validator->errors()->add(
                    'minimum_lifetime_spend_halalah',
                    (string) trans('admin.loyalty.validation.rankOneZero'),
                );
            }

            if ($this->boolean('is_active')) {
                $hasLowerConflict = LoyaltyTier::query()
                    ->whereKeyNot($tier->getKey())
                    ->where('is_active', true)
                    ->where('rank', '<', $tier->rank)
                    ->where('minimum_lifetime_spend_halalah', '>=', $minHalalah)
                    ->exists();

                $hasHigherConflict = LoyaltyTier::query()
                    ->whereKeyNot($tier->getKey())
                    ->where('is_active', true)
                    ->where('rank', '>', $tier->rank)
                    ->where('minimum_lifetime_spend_halalah', '<=', $minHalalah)
                    ->exists();

                if ($hasLowerConflict || $hasHigherConflict) {
                    $validator->errors()->add(
                        'minimum_lifetime_spend_halalah',
                        (string) trans('admin.loyalty.validation.strictlyIncreasing'),
                    );
                }
            }
        });
    }

    public function nameAr(): string
    {
        return (string) $this->input('name_ar');
    }

    public function nameEn(): string
    {
        return (string) $this->input('name_en');
    }

    public function minimumLifetimeSpendHalalah(): int
    {
        return (int) $this->input('minimum_lifetime_spend_halalah');
    }

    public function cashbackBasisPoints(): int
    {
        return (int) $this->input('cashback_basis_points');
    }

    public function isActive(): bool
    {
        return $this->boolean('is_active');
    }
}
