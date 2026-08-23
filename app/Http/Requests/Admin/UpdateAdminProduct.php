<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateAdminProduct extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CatalogManage->value);
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
                'max:255',
            ],
            'name_en' => [
                'required',
                'string',
                'max:255',
            ],
            'description_ar' => [
                'nullable',
                'string',
            ],
            'description_en' => [
                'nullable',
                'string',
            ],
            'is_visible' => [
                'required',
                'boolean',
            ],
            'sort_order' => [
                'required',
                'integer',
                'min:0',
            ],
            'expected' => [
                'required',
                'array:name_ar,name_en,description_ar,description_en,is_visible,sort_order',
            ],
            'expected.name_ar' => ['required', 'string', 'max:255'],
            'expected.name_en' => ['required', 'string', 'max:255'],
            'expected.description_ar' => ['present', 'nullable', 'string'],
            'expected.description_en' => ['present', 'nullable', 'string'],
            'expected.is_visible' => ['required', 'boolean'],
            'expected.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedKeys = [
                'name_ar',
                'name_en',
                'description_ar',
                'description_en',
                'is_visible',
                'sort_order',
                'expected',
            ];
            $extraKeys = array_diff(array_keys($this->all()), $allowedKeys);

            if (! empty($extraKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
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

    public function descriptionAr(): ?string
    {
        $value = $this->input('description_ar');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function descriptionEn(): ?string
    {
        $value = $this->input('description_en');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isVisible(): bool
    {
        return (bool) $this->input('is_visible');
    }

    /**
     * Re-clamped after validation the same way the list requests re-check their
     * allowlists, so the non-negative guarantee the column needs is carried by
     * the type rather than only by the rule.
     *
     * @return int<0, max>
     */
    public function sortOrder(): int
    {
        return max(0, (int) $this->input('sort_order'));
    }

    /**
     * @return array{
     *     name_ar: string,
     *     name_en: string,
     *     description_ar: string|null,
     *     description_en: string|null,
     *     is_visible: bool,
     *     sort_order: int
     * }
     */
    public function expected(): array
    {
        $descAr = $this->input('expected.description_ar');
        $descEn = $this->input('expected.description_en');

        return [
            'name_ar' => (string) $this->input('expected.name_ar'),
            'name_en' => (string) $this->input('expected.name_en'),
            'description_ar' => is_string($descAr) && $descAr !== '' ? $descAr : null,
            'description_en' => is_string($descEn) && $descEn !== '' ? $descEn : null,
            'is_visible' => (bool) $this->input('expected.is_visible'),
            'sort_order' => (int) $this->input('expected.sort_order'),
        ];
    }
}
