<?php

namespace App\Account\Queries;

use App\Account\Presenters\LiveOrderCard;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

final readonly class ReadLiveOrders
{
    public const FILTERS = ['all', 'open', 'completed'];

    private const PER_PAGE = 10;

    public function __construct(private LiveOrderCard $presenter) {}

    /** @return array<string, mixed> */
    public function for(User $user, string $locale, string $status): array
    {
        $query = Order::query()
            ->select([
                'id',
                'public_id',
                'user_id',
                'order_number',
                'status',
                'currency',
                'total_halalah',
                'placed_at',
                'created_at',
            ])
            ->where('user_id', $user->id)
            ->with(['items' => fn ($items) => $items
                ->select(['id', 'order_id', 'name_ar', 'name_en', 'status'])
                ->orderBy('id')]);

        if ($status === 'open') {
            $query->whereIn('status', [
                OrderStatus::PendingPayment->value,
                OrderStatus::Received->value,
                OrderStatus::InProgress->value,
                OrderStatus::WaitingForCustomer->value,
            ]);
        } elseif ($status === 'completed') {
            $query->where('status', OrderStatus::Completed->value);
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
            'filters' => ['status' => $status],
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
