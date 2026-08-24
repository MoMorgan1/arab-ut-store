<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
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
            'mechanic' => ['sometimes', 'string', Rule::in([Promotion::MECHANIC_ITEM, Promotion::MECHANIC_NTH_ITEM, Promotion::MECHANIC_BUNDLE])],
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
            'discount_type' => ['nullable', 'string', Rule::in(['percent', 'fixed'])],
            'value' => ['nullable', 'integer'],
            // Required for nth_item: the engine reads a missing buy/get as 1,
            // so an omitted pair silently becomes "buy 1 get 1" rather than
            // the terms the admin meant to set.
            'buy_quantity' => ['nullable', 'integer', 'min:1', 'required_if:mechanic,'.Promotion::MECHANIC_NTH_ITEM],
            'get_quantity' => ['nullable', 'integer', 'min:1', 'required_if:mechanic,'.Promotion::MECHANIC_NTH_ITEM],
            'max_applications' => ['nullable', 'integer', 'min:1'],
            'discount_target' => ['nullable', 'string', Rule::in([Promotion::TARGET_CHEAPEST, Promotion::TARGET_MOST_EXPENSIVE])],
            'qualifying_scope' => ['nullable', 'string', Rule::in([
                Promotion::QUALIFYING_SCOPE_SAME_PRODUCT,
                Promotion::QUALIFYING_SCOPE_SAME_CATEGORY,
                Promotion::QUALIFYING_SCOPE_SAME_SERVICE,
                Promotion::QUALIFYING_SCOPE_ANY,
            ])],
            'bundle_price_halalah' => ['nullable', 'integer', 'min:1'],
            'applies_to_promoted_items' => ['sometimes', 'boolean'],
            'components' => ['nullable', 'array'],
            'components.*.product_id' => ['required_with:components', 'string'],
            'components.*.quantity' => ['required_with:components', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mechanic = $this->input('mechanic', Promotion::MECHANIC_ITEM);
            $type = $this->input('discount_type');
            $value = $this->input('value');

            if ($mechanic === Promotion::MECHANIC_BUNDLE) {
                $bundlePrice = $this->input('bundle_price_halalah');
                if ($bundlePrice === null || $bundlePrice === '') {
                    $validator->errors()->add('bundle_price_halalah', 'The bundle price is required for bundle promotions.');
                } elseif (! is_numeric($bundlePrice) || (int) $bundlePrice < 1) {
                    $validator->errors()->add('bundle_price_halalah', 'The bundle price must be at least 1 halalah.');
                }

                $components = $this->input('components');
                if (! is_array($components) || count($components) < 2) {
                    $validator->errors()->add('components', 'A bundle must contain at least two components.');
                } else {
                    $seenProducts = [];
                    foreach ($components as $index => $comp) {
                        $pid = $comp['product_id'] ?? $comp['product'] ?? null;
                        if (empty($pid)) {
                            $validator->errors()->add("components.{$index}.product_id", 'The product is required.');
                        } else {
                            $exists = DB::table('products')->where('public_id', $pid)->orWhere('id', $pid)->exists();
                            if (! $exists) {
                                $validator->errors()->add("components.{$index}.product_id", 'The selected product does not exist.');
                            }
                            if (in_array($pid, $seenProducts, true)) {
                                $validator->errors()->add("components.{$index}.product_id", 'Duplicate products are not allowed in bundle components.');
                            }
                            $seenProducts[] = $pid;
                        }

                        $qty = $comp['quantity'] ?? null;
                        if ($qty === null || ! is_numeric($qty) || (int) $qty < 1) {
                            $validator->errors()->add("components.{$index}.quantity", 'The quantity must be at least 1.');
                        }
                    }
                }
            } else {
                if ($type === null || $type === '') {
                    $validator->errors()->add('discount_type', 'The discount type is required.');
                }

                if ($value === null || $value === '') {
                    $validator->errors()->add('value', 'The value field is required.');
                } elseif ($type === 'percent' && is_numeric($value) && ((int) $value < 1 || (int) $value > 90)) {
                    $validator->errors()->add('value', 'The discount percentage must be between 1 and 90.');
                } elseif ($type === 'fixed' && is_numeric($value) && (int) $value < 100) {
                    $validator->errors()->add('value', 'The fixed discount must be at least 100 halalah.');
                } elseif (is_numeric($value) && (int) $value < 1) {
                    $validator->errors()->add('value', 'The value must be at least 1.');
                }
            }

            foreach (['buy_quantity', 'get_quantity', 'max_applications'] as $field) {
                $val = $this->input($field);
                if ($val !== null && $val !== '' && is_numeric($val) && (int) $val < 1) {
                    $validator->errors()->add($field, "The {$field} must be at least 1.");
                }
            }
        });
    }
}
