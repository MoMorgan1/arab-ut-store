<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SetAdminVariantPrice extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = ['price_halalah', 'completion_pricing', 'expected_price_version'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CatalogManage->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // null clears the override and hands pricing back to automation.
            'price_halalah' => ['present', 'nullable', 'integer', 'min:1'],
            'completion_pricing' => ['sometimes', 'nullable', 'array'],
            'expected_price_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknown = array_diff(array_keys($this->all()), self::ALLOWED_KEYS);

                if ($unknown !== []) {
                    $validator->errors()->add('payload', 'Unknown fields are not allowed.');
                }

                if ($this->input('price_halalah') === null && $this->input('completion_pricing') !== null) {
                    $validator->errors()->add(
                        'completion_pricing',
                        'A tier table cannot be set without an override price.',
                    );
                }
            },
        ];
    }

    public function priceHalalah(): ?int
    {
        $value = $this->input('price_halalah');

        return $value === null ? null : (int) $value;
    }

    /** @return array<string, mixed>|null */
    public function completionPricing(): ?array
    {
        $value = $this->input('completion_pricing');

        return is_array($value) ? $value : null;
    }

    public function expectedPriceVersion(): int
    {
        return (int) $this->input('expected_price_version');
    }
}
