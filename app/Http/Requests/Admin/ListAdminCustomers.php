<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminCustomers extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'search',
        'status',
        'date_from',
        'date_to',
        'sort',
        'direction',
        'per_page',
        'page',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::CustomersView->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'suspended'])],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['sometimes', 'string', Rule::in(['created_at', 'name', 'orders_count', 'last_order_at', 'total_spent'])],
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
     *     status: 'active'|'suspended'|null,
     *     date_from: ?string,
     *     date_to: ?string,
     *     sort: 'created_at'|'name'|'orders_count'|'last_order_at'|'total_spent',
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

        $status = ! empty($validated['status']) ? (string) $validated['status'] : null;
        if ($status !== 'active' && $status !== 'suspended') {
            $status = null;
        }

        $sort = (string) ($validated['sort'] ?? 'created_at');
        if (! in_array($sort, ['created_at', 'name', 'orders_count', 'last_order_at', 'total_spent'], true)) {
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
            'status' => $status,
            'date_from' => ! empty($validated['date_from']) ? (string) $validated['date_from'] : null,
            'date_to' => ! empty($validated['date_to']) ? (string) $validated['date_to'] : null,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }
}
