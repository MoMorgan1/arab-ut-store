<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminProducts extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'search',
        'service_type',
        'authority',
        'source',
        'visibility',
        'archived',
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
            'service_type' => ['sometimes', 'nullable', 'string', Rule::enum(ServiceType::class)],
            'authority' => ['sometimes', 'nullable', 'string', Rule::enum(ProductAuthority::class)],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'nullable', 'string', Rule::in(['visible', 'hidden'])],
            'archived' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'archived'])],
            'sort' => ['sometimes', 'string', Rule::in(['name', 'created_at', 'updated_at', 'sort_order'])],
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
     *     service_type: ?string,
     *     authority: 'manual'|'automation'|null,
     *     source: ?string,
     *     visibility: 'visible'|'hidden'|null,
     *     archived: 'active'|'archived'|null,
     *     sort: 'name'|'created_at'|'updated_at'|'sort_order',
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

        $serviceType = ! empty($validated['service_type']) ? (string) $validated['service_type'] : null;
        if ($serviceType !== null && ServiceType::tryFrom($serviceType) === null) {
            $serviceType = null;
        }

        $authority = ! empty($validated['authority']) ? (string) $validated['authority'] : null;
        if ($authority !== 'manual' && $authority !== 'automation') {
            $authority = null;
        }

        $source = isset($validated['source']) ? trim((string) $validated['source']) : null;
        if ($source === '') {
            $source = null;
        }

        $visibility = ! empty($validated['visibility']) ? (string) $validated['visibility'] : null;
        if ($visibility !== 'visible' && $visibility !== 'hidden') {
            $visibility = null;
        }

        $archived = ! empty($validated['archived']) ? (string) $validated['archived'] : null;
        if ($archived !== 'active' && $archived !== 'archived') {
            $archived = null;
        }

        $sort = (string) ($validated['sort'] ?? 'created_at');
        if (! in_array($sort, ['name', 'created_at', 'updated_at', 'sort_order'], true)) {
            $sort = 'created_at';
        }

        $direction = strtolower((string) ($validated['direction'] ?? 'desc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 15;
        }

        $page = max(1, (int) ($validated['page'] ?? 1));

        return [
            'search' => $search,
            'service_type' => $serviceType,
            'authority' => $authority,
            'source' => $source,
            'visibility' => $visibility,
            'archived' => $archived,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }
}
