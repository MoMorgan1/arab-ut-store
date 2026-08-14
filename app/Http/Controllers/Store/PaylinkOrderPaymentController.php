<?php

namespace App\Http\Controllers\Store;

use App\Actions\Checkout\ReconcilePaylinkPayment;
use App\Actions\Checkout\StartPaylinkPayment;
use App\Enums\OrderStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaylinkOrderPaymentController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        StartPaylinkPayment $startPayment,
        ReconcilePaylinkPayment $reconcile,
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $order->user_id === $user->id, 404);

        $payment = $order->payments()
            ->where('provider', 'paylink')
            ->latest('id')
            ->first();

        if ($order->status !== OrderStatus::PendingPayment || ! $payment instanceof Payment) {
            return $this->error('payment_unavailable', trans('store.checkout.payment_unavailable'), 409);
        }

        try {
            $invoice = $startPayment->execute($order, $payment);

            if ($invoice->status !== 'pending') {
                $reconcile->execute($payment);
            }
        } catch (CheckoutUnavailable|PaymentConfigurationException|PaymentGatewayException) {
            return $this->error('payment_unavailable', trans('store.checkout.payment_unavailable'), 503);
        }

        $localized = $order->locale === 'en';
        $orderUrl = route(
            $localized ? 'localized.store.orders.show' : 'store.orders.show',
            [...($localized ? ['locale' => 'en'] : []), 'order' => $order->public_id],
            absolute: false,
        );

        return response()->json(['data' => [
            'paymentUrl' => $invoice->status === 'pending' ? $invoice->paymentUrl : null,
            'orderUrl' => $orderUrl,
            'status' => $invoice->status,
        ]])->header('Cache-Control', 'no-store, private');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => compact('code', 'message')], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
