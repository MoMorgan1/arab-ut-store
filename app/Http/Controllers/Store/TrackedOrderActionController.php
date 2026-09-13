<?php

namespace App\Http\Controllers\Store;

use App\Actions\Fulfillment\ResumeItemDelivery;
use App\Actions\Fulfillment\RetryItemChallenge;
use App\Actions\Fulfillment\SubmitCredentialCorrection;
use App\Enums\CredentialCorrectionSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SubmitCredentialCorrectionRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\PublicHandle\TrackingLinkHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three self-service actions, reachable over a signed tracking link.
 *
 * This is the sibling of Account\ItemTrackingActionController, not its subclass:
 * there is no session here, so the authorisation boundary is the capability
 * token rather than an authenticated user. The shared logic already lives in
 * the three actions; this controller only proves the token names this order
 * and this item, then defers.
 */
final class TrackedOrderActionController extends Controller
{
    public function __construct(
        private readonly SubmitCredentialCorrection $submitCorrection,
        private readonly ResumeItemDelivery $resumeDelivery,
        private readonly RetryItemChallenge $retryChallenge,
    ) {}

    public function editCredentials(SubmitCredentialCorrectionRequest $request): JsonResponse
    {
        $item = $this->resolve($request);

        $result = $this->submitCorrection->execute(
            $item,
            $request->user(),
            CredentialCorrectionSource::TrackingLink,
            (string) $request->ip(),
            $request->validated(),
            app()->getLocale(),
        );

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function resume(Request $request): JsonResponse
    {
        $item = $this->resolve($request);

        $result = $this->resumeDelivery->execute($item, app()->getLocale());

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function retryChallenge(Request $request): JsonResponse
    {
        $item = $this->resolve($request);

        $result = $this->retryChallenge->execute($item, $request->input('target'), app()->getLocale());

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    /**
     * The whole authorisation boundary: the token names one order, and the item
     * is only ever looked up inside that order.
     */
    private function resolve(Request $request): OrderItem
    {
        $token = (string) $request->route('token');
        $order = TrackingLinkHandle::resolve($token);

        // A revoked token and an unknown one answer identically: a 404, never a
        // 403, because a 403 would confirm that a token of that shape exists.
        abort_unless($order instanceof Order, 404);

        $itemHandle = (string) $request->route('item');

        // Scoping the item to the resolved order is the gate. A token authorises
        // exactly one order; without the order_id clause, a customer holding any
        // valid link could name an item from another order and drive actions on
        // it. The public_id-or-id shape matches the account controller.
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

        return $item;
    }
}
