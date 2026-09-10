<?php

namespace App\Http\Controllers\Account;

use App\Actions\Checkout\CancelPendingOrder;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CancelPendingOrderController extends Controller
{
    public function __invoke(Request $request, string $order, CancelPendingOrder $cancel): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = Order::query()
            ->where('public_id', $order)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $cancel->execute($user, $model);
        } catch (CheckoutUnavailable) {
            abort(409);
        }

        return back()->with('status', 'order-cancelled');
    }
}
