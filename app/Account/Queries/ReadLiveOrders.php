<?php

namespace App\Account\Queries;

use App\Account\Presenters\LiveOrderCard;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

final readonly class ReadLiveOrders
{
    public const FILTERS = ['all', 'open', 'completed'];

    public const OPEN_STATUSES = [
        OrderStatus::PendingPayment->value,
        OrderStatus::Received->value,
        OrderStatus::InProgress->value,
        OrderStatus::WaitingForCustomer->value,
    ];

    public const COMPLETED_STATUSES = [
        OrderStatus::Completed->value,
    ];

    private const PER_PAGE = 10;

    public function __construct(private LiveOrderCard $presenter) {}

    /**
     * @return array{
     *     orders: array<int, array{
     *         id: string,
     *         source: string,
     *         number: string,
     *         status: string,
     *         placedAt: string,
     *         summary: string,
     *         itemCount: int,
     *         total: array{amountMinor: string, currency: string},
     *         detailUrl: string
     *     }>,
     *     filters: array{status: string, q: ?string},
     *     pagination: array{
     *         currentPage: int,
     *         lastPage: int,
     *         perPage: int,
     *         total: int,
     *         nextUrl: ?string,
     *         previousUrl: ?string
     *     }
     * }
     */
    public function for(User $user, string $locale, string $status, ?string $q = null): array
    {
        $trimmedQ = is_string($q) ? trim($q) : '';

        $query = Order::query()
            ->select([
                'orders.id',
                'orders.public_id',
                'orders.user_id',
                'orders.order_number',
                'orders.status',
                'orders.currency',
                'orders.wallet_halalah',
                'orders.total_halalah',
                'orders.placed_at',
                'orders.created_at',
            ])
            ->where('orders.user_id', $user->id)
            ->with(['items' => fn ($items) => $items
                ->select(['id', 'order_id', 'name_ar', 'name_en', 'status'])
                ->orderBy('id')]);

        if ($status === 'open') {
            $query->whereIn('orders.status', self::OPEN_STATUSES);
        } elseif ($status === 'completed') {
            $query->whereIn('orders.status', self::COMPLETED_STATUSES);
        }

        if ($trimmedQ !== '') {
            $pattern = '%'.$trimmedQ.'%';
            $query->where(function ($orderQuery) use ($pattern): void {
                $orderQuery
                    ->where('orders.order_number', 'LIKE', $pattern)
                    ->orWhere('orders.public_id', 'LIKE', $pattern)
                    ->orWhereHas('items', function ($itemQuery) use ($pattern): void {
                        $itemQuery
                            ->where('name_ar', 'LIKE', $pattern)
                            ->orWhere('name_en', 'LIKE', $pattern);
                    });
            });
        }

        $paginator = $query
            ->orderByRaw('COALESCE(orders.placed_at, orders.created_at) DESC')
            ->orderByDesc('orders.public_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'orders' => $paginator->getCollection()
                ->map(fn (Order $order): array => $this->presenter->for($order, $locale))
                ->values()
                ->all(),
            'filters' => [
                'status' => $status,
                'q' => $trimmedQ !== '' ? $trimmedQ : null,
            ],
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'nextUrl' => $this->relativeUrl($paginator->nextPageUrl()),
                'previousUrl' => $this->relativeUrl($paginator->previousPageUrl()),
            ],
        ];
    }

    private function relativeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) ? $path : '').(is_string($query) ? "?{$query}" : '');
    }
}
