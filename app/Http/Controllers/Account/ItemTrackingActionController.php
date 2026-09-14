<?php

namespace App\Http\Controllers\Account;

use App\Actions\Fulfillment\ResumeItemDelivery;
use App\Actions\Fulfillment\RetryItemChallenge;
use App\Actions\Fulfillment\SubmitCredentialCorrection;
use App\Enums\CredentialCorrectionSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SubmitCredentialCorrectionRequest;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\PublicHandle\OrderHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemTrackingActionController extends Controller
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
            CredentialCorrectionSource::Account,
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

    private function resolve(Request $request): OrderItem
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

        return $item;
    }
}
