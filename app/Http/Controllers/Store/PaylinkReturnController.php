<?php

namespace App\Http\Controllers\Store;

use App\Actions\Checkout\ReconcilePaylinkPayment;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PaylinkReturnController extends Controller
{
    public function __invoke(Request $request, ReconcilePaylinkPayment $reconcile): RedirectResponse
    {
        $transactionNo = $request->query('transactionNo') ?? $request->query('TransactionNo');
        $orderNumber = $request->query('orderNumber') ?? $request->query('OrderNumber');
        $user = $request->user();

        if (! is_string($transactionNo)
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $transactionNo) !== 1
            || ($orderNumber !== null && (! is_string($orderNumber)
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $orderNumber) !== 1))
            || ! $user instanceof User) {
            return $this->cartRedirect($request);
        }

        $payment = Payment::query()
            ->where('provider', 'paylink')
            ->where('provider_payment_id', $transactionNo)
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id))
            ->when(is_string($orderNumber), fn ($query) => $query->whereHas(
                'order',
                fn ($orderQuery) => $orderQuery->where('order_number', $orderNumber),
            ))
            ->first();

        if (! $payment instanceof Payment) {
            return $this->cartRedirect($request);
        }

        try {
            $reconcile->execute($payment);
        } catch (CheckoutUnavailable|PaymentConfigurationException|PaymentGatewayException) {
            // The order page remains truthful and pending when Paylink cannot be verified.
        }

        $localized = $request->route('locale') === 'en';

        return redirect()->to(route(
            $localized ? 'localized.store.orders.show' : 'store.orders.show',
            [...($localized ? ['locale' => 'en'] : []), 'order' => $payment->order->public_id],
            absolute: false,
        ));
    }

    private function cartRedirect(Request $request): RedirectResponse
    {
        $localized = $request->route('locale') === 'en';

        return redirect()->to(route(
            $localized ? 'localized.store.cart' : 'store.cart',
            $localized ? ['locale' => 'en'] : [],
            absolute: false,
        ));
    }
}
