<?php

namespace App\Http\Controllers\Account;

use App\Actions\Fulfillment\RefreshItemTracking;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\PublicHandle\OrderHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemTrackingRefreshController extends Controller
{
    public function __construct(
        private readonly RefreshItemTracking $refreshTracking,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $orderHandle = (string) $request->route('order');
        $itemHandle = (string) $request->route('item');

        // OrderHandle::resolveForCustomer verifies that the authenticated customer owns
        // this order. If the order belongs to another customer or does not exist,
        // it throws ModelNotFoundException which Laravel renders as a 404 response.
        $order = OrderHandle::resolveForCustomer($user, $orderHandle);

        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->where(function ($query) use ($itemHandle): void {
                $query->where('public_id', $itemHandle);
                if (ctype_digit($itemHandle)) {
                    $query->orWhere('id', (int) $itemHandle);
                }
            })
            ->firstOrFail();

        $item->setRelation('order', $order);

        $tracking = $this->refreshTracking->execute($item, app()->getLocale());

        // Wrapped rather than returned bare: a null tracking object would send an
        // empty body, leaving the client to tell "nothing to track" apart from a
        // failed request. One shape either way, and the same key the order page uses.
        return response()->json(['tracking' => $tracking])->header('Cache-Control', 'no-store, private');
    }
}
