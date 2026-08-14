<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController extends Controller
{
    public function __invoke(Request $request, string $order): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        $stored = Order::query()
            ->where('public_id', $order)
            ->where('user_id', $user->id)
            ->with(['items', 'payments'])
            ->firstOrFail();

        return Inertia::render('store/order', [
            'orderPage' => trans('store.order_page'),
            'order' => [
                'id' => $stored->public_id,
                'number' => $stored->order_number,
                'status' => $stored->status->value,
                'paymentStartUrl' => $stored->status->value === 'pending_payment'
                    ? route(
                        $stored->locale === 'en'
                            ? 'localized.store.orders.paylink-payment'
                            : 'store.orders.paylink-payment',
                        [...($stored->locale === 'en' ? ['locale' => 'en'] : []), 'order' => $stored->public_id],
                        absolute: false,
                    )
                    : null,
                'currency' => 'SAR',
                'totalHalalah' => $stored->total_halalah,
                'items' => $stored->items->map(fn (OrderItem $item): array => [
                    'id' => $item->public_id,
                    'name' => $stored->locale === 'en' ? $item->name_en : $item->name_ar,
                    'status' => $item->status->value,
                    'totalHalalah' => $item->total_halalah,
                ])->values()->all(),
            ],
        ]);
    }
}
