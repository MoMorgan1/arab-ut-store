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
        'ticket_status',
        'locale',
        // `owner` is deliberately still accepted. Its only legal value was
        // `customer`, and with guests excluded unconditionally that is a no-op,
        // so the control is gone from the UI — but a bookmarked URL still
        // carries it and must degrade to "all customers", not 422.
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

    protected function prepareForValidation(): void
    {
        if ($this->has('owner') && $this->input('owner') !== 'customer') {
            $this->merge(['owner' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['open', 'closed'])],
            'ticket_status' => ['sometimes', 'nullable', 'string', Rule::in(['open', 'resolved', 'closed'])],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['ar', 'en'])],
            'owner' => ['sometimes', 'nullable', 'string', Rule::in(['customer'])],
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
     *     ticket_status: 'open'|'resolved'|'closed'|null,
     *     locale: 'ar'|'en'|null,
     *     owner: 'customer'|null,
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

        $ticketStatus = ! empty($validated['ticket_status']) ? (string) $validated['ticket_status'] : null;
        if (! in_array($ticketStatus, ['open', 'resolved', 'closed'], true)) {
            $ticketStatus = null;
        }

        $locale = ! empty($validated['locale']) ? (string) $validated['locale'] : null;
        if ($locale !== 'ar' && $locale !== 'en') {
            $locale = null;
        }

        $owner = ! empty($validated['owner']) && (string) $validated['owner'] === 'customer' ? 'customer' : null;

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
            'ticket_status' => $ticketStatus,
            'locale' => $locale,
            'owner' => $owner,
            'q' => $q,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }
}
