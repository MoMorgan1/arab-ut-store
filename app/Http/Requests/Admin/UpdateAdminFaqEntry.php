<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAdminFaqEntry extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::MarketingManage->value);
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['question_ar', 'question_en', 'answer_ar', 'answer_en'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized = str_replace(["\r\n", "\r"], "\n", $value);
                $stripped = preg_replace('/[^\P{C}\n]/u', '', $normalized);
                $merged[$field] = is_string($stripped) ? trim($stripped) : '';
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_ar' => ['required', 'string', 'max:200'],
            'question_en' => ['required', 'string', 'max:200'],
            'answer_ar' => ['required', 'string', 'max:2000'],
            'answer_en' => ['required', 'string', 'max:2000'],
        ];
    }
}
