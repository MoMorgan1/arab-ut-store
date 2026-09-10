<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Account\Queries\ReadLiveOrder;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublicHandle\OrderHandle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LiveOrderController extends Controller
{
    public function __construct(
        private readonly ReadLiveOrder $order,
        private readonly AccountShell $shell,
    ) {}

    public function __invoke(Request $request, string $order): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $locale = app()->getLocale();

        if (OrderHandle::isUlid($order)) {
            $redirect = OrderHandle::legacyRedirect($request, OrderHandle::resolveForCustomer($user, $order));

            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }
        }

        return Inertia::render('account/live-order', [
            ...$this->shell->for($user, $locale),
            'order' => $this->order->for($user, $order, $locale),
        ]);
    }
}
