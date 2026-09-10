<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublicHandle\OrderHandle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        $order = $request->route('order');

        abort_unless($user instanceof User, 401);
        abort_unless(is_string($order), 404);
        $stored = OrderHandle::resolveForCustomer($user, $order);

        return redirect()->to(route(
            app()->getLocale() === 'en'
                ? 'localized.account.orders.show'
                : 'account.orders.show',
            ['order' => (string) $stored->getAttribute('order_number')],
            absolute: false,
        ));
    }
}
