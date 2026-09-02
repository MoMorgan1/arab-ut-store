<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminReviews extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'search',
        'status',
        'rating',
        'source',
        'service',
        'per_page',
        'page',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::MarketingView->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'visible', 'hidden'])],
            'rating' => ['sometimes', 'nullable', 'string', Rule::in(['all', '5', '4', '3', '2', '1'])],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'customer', 'archive'])],
            'service' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'rivals', 'fut_champions', 'sbc', 'objectives'])],
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
     *     status: 'all'|'visible'|'hidden',
     *     rating: 'all'|'5'|'4'|'3'|'2'|'1',
     *     source: 'all'|'customer'|'archive',
     *     service: 'all'|'rivals'|'fut_champions'|'sbc'|'objectives',
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

        $status = (string) ($validated['status'] ?? 'all');
        if (! in_array($status, ['all', 'visible', 'hidden'], true)) {
            $status = 'all';
        }

        $rating = (string) ($validated['rating'] ?? 'all');
        if (! in_array($rating, ['all', '5', '4', '3', '2', '1'], true)) {
            $rating = 'all';
        }

        $source = (string) ($validated['source'] ?? 'all');
        if (! in_array($source, ['all', 'customer', 'archive'], true)) {
            $source = 'all';
        }

        $service = (string) ($validated['service'] ?? 'all');
        if (! in_array($service, ['all', 'rivals', 'fut_champions', 'sbc', 'objectives'], true)) {
            $service = 'all';
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 15;
        }

        return [
            'search' => $search,
            'status' => $status,
            'rating' => $rating,
            'source' => $source,
            'service' => $service,
            'per_page' => $perPage,
            'page' => max(1, (int) ($validated['page'] ?? 1)),
        ];
    }
}
