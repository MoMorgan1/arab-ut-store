<?php

namespace App\Http\Controllers\Store;

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Checkout\ReconcilePaylinkPayment;
use App\Actions\Checkout\StartPaylinkPayment;
use App\Enums\OrderStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\PaylinkCheckoutRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class PaylinkCheckoutController extends Controller
{
    public function __invoke(
        PaylinkCheckoutRequest $request,
        PlaceOrder $placeOrder,
        StartPaylinkPayment $startPayment,
        ReconcilePaylinkPayment $reconcile,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->error('authentication_required', trans('store.checkout.authentication_required'), 401);
        }

        try {
            $checkout = $placeOrder->execute(
                $user,
                $request->route('locale') === 'en' ? 'en' : 'ar',
                $request->idempotencyKey(),
            );

            $localized = $checkout->order->locale === 'en';
            $orderUrl = route(
                $localized ? 'localized.store.orders.show' : 'store.orders.show',
                [...($localized ? ['locale' => 'en'] : []), 'order' => $checkout->order->public_id],
                absolute: false,
            );

            if ($checkout->payment->provider === 'wallet' || $checkout->order->status !== OrderStatus::PendingPayment) {
                return response()->json(['data' => [
                    'paymentUrl' => null,
                    'orderUrl' => $orderUrl,
                    'status' => 'paid',
                ]], $checkout->replayed ? 200 : 201)->header('Cache-Control', 'no-store, private');
            }

            $invoice = $startPayment->execute($checkout->order, $checkout->payment);
        } catch (IdempotencyConflict) {
            return $this->error('idempotency_conflict', trans('store.checkout.idempotency_conflict'), 409);
        } catch (CheckoutUnavailable $exception) {
            $phoneRequired = $exception->getMessage() === 'A verified mobile number is required.';

            return $this->error(
                $phoneRequired ? 'phone_verification_required' : 'cart_changed',
                trans($phoneRequired ? 'store.checkout.phone_verification_required' : 'store.checkout.cart_changed'),
                422,
            );
        } catch (PaymentConfigurationException|PaymentGatewayException) {
            return $this->error('payment_unavailable', trans('store.checkout.payment_unavailable'), 503);
        }

        if ($invoice->status !== 'pending') {
            try {
                $reconcile->execute($checkout->payment);
            } catch (CheckoutUnavailable|PaymentConfigurationException|PaymentGatewayException) {
                return $this->error('payment_unavailable', trans('store.checkout.payment_unavailable'), 503);
            }

            return response()->json(['data' => [
                'paymentUrl' => null,
                'orderUrl' => $orderUrl,
                'status' => $invoice->status,
            ]], $checkout->replayed ? 200 : 201)->header('Cache-Control', 'no-store, private');
        }

        if ($invoice->paymentUrl === null) {
            return $this->error('payment_unavailable', trans('store.checkout.payment_unavailable'), 503);
        }

        return response()->json(['data' => [
            'paymentUrl' => $invoice->paymentUrl,
            'orderUrl' => $orderUrl,
            'status' => 'pending',
        ]], $checkout->replayed ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => compact('code', 'message')], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
