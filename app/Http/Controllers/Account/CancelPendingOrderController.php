<?php

namespace App\Http\Controllers\Account;

use App\Actions\Checkout\CancelPendingOrder;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
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
            // Paid meanwhile, or already closed: the page reloads with the
            // order's real state and says why nothing was cancelled.
            return back()->with('status', 'order-cancel-refused');
        } catch (PaymentConfigurationException|PaymentGatewayException) {
            return back()->with('status', 'paylink-unavailable');
        }

        return back()->with('status', 'order-cancelled');
    }
}
