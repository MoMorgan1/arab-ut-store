<?php

namespace App\Http\Requests\Admin;

use App\Admin\Queries\ListAdminFulfillment as FulfillmentQuery;
use App\Enums\AdminPermission;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderHoldReason;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminFulfillment extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'search',
        'supplier',
        'phase',
        'status',
        'alarm',
        'hold',
        'service',
        'paid_from',
        'paid_to',
        'sort',
        'direction',
        'per_page',
        'page',
    ];

    /**
     * The job statuses worth filtering by.
     *
     * `failed` is deliberately absent, and it is the one omission worth a
     * comment. `FulfillmentStatus::Failed` is never assigned anywhere in the
     * application - it appears only as an exclusion in the poller's selection
     * and the alarm sweep - so a `failed` chip could never match a row. Owner
     * decision, 2026-09-17: an absent filter beats one that always returns
     * nothing.
     *
     * @return list<string>
     */
    private static function statuses(): array
    {
        return array_values(array_diff(
            array_map(static fn (FulfillmentStatus $status): string => $status->value, FulfillmentStatus::cases()),
            [FulfillmentStatus::Failed->value],
        ));
    }

    /** @return list<string> */
    private static function alarms(): array
    {
        return [...FulfillmentQuery::alarmKinds(), 'any', 'none'];
    }

    /** @return list<string> */
    private static function holds(): array
    {
        return [...OrderHoldReason::values(), 'held', 'clear'];
    }

    /** @return list<string> */
    private static function services(): array
    {
        return [ServiceType::Coins->value, ServiceType::Sbc->value];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::FulfillmentView->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplier' => ['sometimes', 'nullable', 'string', Rule::in(Supplier::values())],
            'phase' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                static fn (DeliveryPhase $phase): string => $phase->value,
                DeliveryPhase::cases(),
            ))],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(self::statuses())],
            'alarm' => ['sometimes', 'nullable', 'string', Rule::in(self::alarms())],
            'hold' => ['sometimes', 'nullable', 'string', Rule::in(self::holds())],
            'service' => ['sometimes', 'nullable', 'string', Rule::in(self::services())],
            'paid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'paid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'string', Rule::in(FulfillmentQuery::sortKeys())],
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
            // Sorting by a column the actor may not read is refused, not
            // quietly ignored - a silent fallback tells the caller the column
            // exists and only the ordering was dropped. The message is the
            // same one an invented key gets, for the same reason: a distinct
            // "not available for you" is itself the answer to "does this
            // column exist?".
            function (Validator $validator): void {
                if ($this->input('sort') !== 'actual_cost') {
                    return;
                }

                if (! $this->canSeeCost()) {
                    $validator->errors()->add('sort', 'The selected sort is invalid.');
                }
            },
        ];
    }

    public function canSeeCost(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::FulfillmentViewCost->value);
    }

    public function canAct(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::FulfillmentAct->value);
    }

    /**
     * @return array{
     *     search: ?string,
     *     supplier: ?string,
     *     phase: ?string,
     *     status: ?string,
     *     alarm: ?string,
     *     hold: ?string,
     *     service: ?string,
     *     paid_from: ?string,
     *     paid_to: ?string,
     *     sort: string,
     *     direction: 'asc'|'desc',
     *     per_page: int,
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

        $sort = (string) ($validated['sort'] ?? 'paid_at');

        if (! in_array($sort, FulfillmentQuery::sortKeys(), true)) {
            $sort = 'paid_at';
        }

        $perPage = (int) ($validated['per_page'] ?? 15);

        if (! in_array($perPage, [15, 25, 50, 100], true)) {
            $perPage = 15;
        }

        return [
            'search' => $search,
            'supplier' => $this->nullableString($validated['supplier'] ?? null),
            'phase' => $this->nullableString($validated['phase'] ?? null),
            'status' => $this->nullableString($validated['status'] ?? null),
            'alarm' => $this->nullableString($validated['alarm'] ?? null),
            'hold' => $this->nullableString($validated['hold'] ?? null),
            'service' => $this->nullableString($validated['service'] ?? null),
            'paid_from' => $this->nullableString($validated['paid_from'] ?? null),
            'paid_to' => $this->nullableString($validated['paid_to'] ?? null),
            'sort' => $sort,
            // Ascending by default, and the default sort is time since paid:
            // the longest-waiting customer belongs at the top, and `paid_at`
            // is the only clock every row on this screen has - an item that
            // was never placed has no placement to measure from.
            'direction' => ($validated['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'per_page' => $perPage,
            'page' => max(1, (int) ($validated['page'] ?? 1)),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
