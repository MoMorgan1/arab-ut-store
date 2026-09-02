<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAdminStorePage extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::MarketingManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ar' => ['required', 'array'],
            'ar.title' => ['required', 'string', 'max:120'],
            'ar.subtitle' => ['nullable', 'string', 'max:120'],
            'ar.updatedLabel' => ['required', 'string', 'max:60'],
            'ar.blocks' => ['required', 'array', 'min:1', 'max:60'],
            'ar.blocks.*.type' => ['required', 'string', 'in:paragraph,heading,list,notice,divider'],
            'ar.blocks.*.level' => ['nullable', 'integer', 'in:2,3'],
            'ar.blocks.*.ordered' => ['nullable', 'boolean'],
            'ar.blocks.*.tone' => ['nullable', 'string', 'in:info,shield,warning'],
            'ar.blocks.*.text' => ['nullable', 'string', 'max:4000'],

            'en' => ['required', 'array'],
            'en.title' => ['required', 'string', 'max:120'],
            'en.subtitle' => ['nullable', 'string', 'max:120'],
            'en.updatedLabel' => ['required', 'string', 'max:60'],
            'en.blocks' => ['required', 'array', 'min:1', 'max:60'],
            'en.blocks.*.type' => ['required', 'string', 'in:paragraph,heading,list,notice,divider'],
            'en.blocks.*.level' => ['nullable', 'integer', 'in:2,3'],
            'en.blocks.*.ordered' => ['nullable', 'boolean'],
            'en.blocks.*.tone' => ['nullable', 'string', 'in:info,shield,warning'],
            'en.blocks.*.text' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
