<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminCategories extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'search',
        'visibility',
        'source',
        'sort',
        'direction',
        'per_page',
        'page',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CatalogView->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'nullable', 'string', Rule::in(['visible', 'admin_hidden', 'automation_hidden'])],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in(['sort_order', 'name', 'created_at', 'updated_at'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
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
     *     search: ?string,
     *     visibility: 'visible'|'admin_hidden'|'automation_hidden'|null,
     *     source: ?string,
     *     sort: 'sort_order'|'name'|'created_at'|'updated_at',
     *     direction: 'asc'|'desc',
     *     per_page: 15|25|50|100,
     *     page: int
     * }
     */
    public function normalizedFilters(): array
    {
        $validated = $this->validated();

        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        if ($search === '') {
            $search = null;
        }

        $visibility = ! empty($validated['visibility']) ? (string) $validated['visibility'] : null;
        if (! in_array($visibility, ['visible', 'admin_hidden', 'automation_hidden'], true)) {
            $visibility = null;
        }

        $source = isset($validated['source']) ? trim((string) $validated['source']) : null;
        if ($source === '') {
            $source = null;
        }

        $sort = (string) ($validated['sort'] ?? 'sort_order');
        if (! in_array($sort, ['sort_order', 'name', 'created_at', 'updated_at'], true)) {
            $sort = 'sort_order';
        }

        $defaultDirection = ($sort === 'sort_order' || $sort === 'name') ? 'asc' : 'desc';
        $direction = strtolower((string) ($validated['direction'] ?? $defaultDirection));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 15;
        }

        $page = max(1, (int) ($validated['page'] ?? 1));

        return [
            'search' => $search,
            'visibility' => $visibility,
            'source' => $source,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }
}
