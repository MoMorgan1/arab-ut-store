<?php

namespace App\Http\Controllers\Store;

use App\Account\Queries\ReadTrackedOrder;
use App\Actions\Fulfillment\RefreshOrderTracking;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\PublicHandle\TrackingLinkHandle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TrackedOrderController extends Controller
{
    public function __construct(
        private readonly ReadTrackedOrder $order,
        private readonly RefreshOrderTracking $refreshTracking,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        $order = TrackingLinkHandle::resolve($token);

        // A wrong token and a revoked one answer identically: a 404, never a 403,
        // because a 403 would confirm to whoever holds the link that a token of
        // that shape exists.
        abort_unless($order instanceof Order, 404);

        $locale = app()->getLocale();

        // "Opening the page is the refresh" - the owner's decision, and it holds
        // here more than on the account page, because this is the page a customer
        // actually opens: it arrives in the WhatsApp message that told them
        // something needs doing. Reading only what the last sweep stored would
        // show them a value up to three minutes old at the moment they went to
        // look.
        //
        // It also stamps attention. RefreshItemTracking writes last_viewed_at on
        // a successful read, which is what moves the job into the 25-second
        // polling band. Without this call a customer who only ever uses their
        // link - who has no account session at all - could watch an order all day
        // and never once tell us they were watching.
        //
        // The cost is bounded the same way the account page's is: the per-job
        // cache lock, the per-supplier limiter and circuit breaker in
        // SupplierGuard, this route's own throttle, and the six-second wall-clock
        // budget inside RefreshOrderTracking.
        $this->refreshTracking->execute($order, $locale);

        return Inertia::render('store/track-order', [
            'order' => $this->order->execute($order, $locale),
            'accountUi' => trans('account', locale: $locale),
        ]);
    }
}
