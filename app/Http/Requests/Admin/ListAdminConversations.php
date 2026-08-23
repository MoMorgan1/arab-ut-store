<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminConversations extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'status',
        'locale',
        'owner',
        'q',
        'per_page',
        'page',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::ChatView->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['open', 'closed'])],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['ar', 'en'])],
            'owner' => ['sometimes', 'nullable', 'string', Rule::in(['guest', 'customer'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', Rule::in([15, 25, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $inputKeys = array_unique(array_merge(array_keys($this->query()), array_keys($this->all())));
                $unknownKeys = array_diff($inputKeys, self::ALLOWED_KEYS);

                if ($unknownKeys !== []) {
                    $validator->errors()->add('query', 'Unknown query parameters are not allowed.');
                }
            },
        ];
    }

    /**
     * @return array{
     *     status: 'open'|'closed'|null,
     *     locale: 'ar'|'en'|null,
     *     owner: 'guest'|'customer'|null,
     *     q: ?string,
     *     per_page: 15|25|50|100,
     *     page: int
     * }
     */
    public function normalizedFilters(): array
    {
        $validated = $this->validated();

        $status = ! empty($validated['status']) ? (string) $validated['status'] : null;
        if ($status !== 'open' && $status !== 'closed') {
            $status = null;
        }

        $locale = ! empty($validated['locale']) ? (string) $validated['locale'] : null;
        if ($locale !== 'ar' && $locale !== 'en') {
            $locale = null;
        }

        $owner = ! empty($validated['owner']) ? (string) $validated['owner'] : null;
        if ($owner !== 'guest' && $owner !== 'customer') {
            $owner = null;
        }

        $q = isset($validated['q']) ? trim((string) $validated['q']) : null;
        if ($q === '') {
            $q = null;
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 15;
        }

        $page = max(1, (int) ($validated['page'] ?? 1));

        return [
            'status' => $status,
            'locale' => $locale,
            'owner' => $owner,
            'q' => $q,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }
}
