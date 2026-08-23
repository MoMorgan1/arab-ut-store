<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ToggleAdminPromotionStatus extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }
}
