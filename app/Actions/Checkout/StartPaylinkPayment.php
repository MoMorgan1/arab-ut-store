<?php

namespace App\Actions\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Checkout\OrderNoLongerPending;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentInvoice;
use App\Payments\PaymentInvoiceRequest;
use App\Services\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;

final readonly class StartPaylinkPayment
{
    public function __construct(private PaymentManager $payments) {}

    public function execute(Order $order, Payment $payment): PaymentInvoice
    {
        if ($payment->order_id !== $order->id
            || $payment->provider !== 'paylink'
            || $payment->amount_halalah !== $order->payment_halalah
            || $order->currency !== 'SAR') {
            throw new CheckoutUnavailable('The payment cannot be started.');
        }

        $gateway = $this->payments->gateway();
        $invoice = is_string($payment->provider_payment_id) && $payment->provider_payment_id !== ''
            ? $gateway->getInvoice($payment->provider_payment_id)
            : $gateway->createInvoice($this->request($order, $payment));

        if ($invoice->orderNumber !== $order->order_number
            || $invoice->amountHalalah !== $payment->amount_halalah
            || $invoice->currency !== 'SAR') {
            throw new CheckoutUnavailable('Paylink returned a mismatched invoice.');
        }

        try {
            DB::transaction(function () use ($order, $payment, $invoice): void {
                // The order may have been cancelled while the invoice was being
                // created. A cancelled order must never keep a chargeable
                // invoice, so the fresh one is closed again and nothing is
                // written.
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->sole();

                if ($lockedOrder->status !== OrderStatus::PendingPayment) {
                    throw new OrderNoLongerPending;
                }

                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->sole();

                if (in_array($locked->status, [
                    PaymentStatus::Authorized,
                    PaymentStatus::Paid,
                    PaymentStatus::PartiallyRefunded,
                    PaymentStatus::Refunded,
                ], true)) {
                    return;
                }

                $locked->forceFill([
                    'provider_payment_id' => $invoice->transactionNo,
                    'status' => PaymentStatus::Pending,
                    'captured_halalah' => 0,
                    'paid_at' => null,
                    'provider_metadata' => [
                        'payment_url' => $invoice->paymentUrl,
                        'provider_status' => $invoice->status,
                    ],
                ])->save();
            }, attempts: 3);
        } catch (OrderNoLongerPending) {
            if ($invoice->status === 'pending') {
                $gateway->cancelInvoice($invoice->transactionNo);
            }

            throw new CheckoutUnavailable('The order is no longer awaiting payment.');
        }

        return $invoice;
    }

    private function request(Order $order, Payment $payment): PaymentInvoiceRequest
    {
        $order->loadMissing(['items', 'user']);
        $products = [];

        foreach ($order->items as $item) {
            $products[] = [
                'title' => $order->locale === 'en' ? $item->name_en : $item->name_ar,
                'priceHalalah' => $item->total_halalah,
                'quantity' => 1,
            ];
        }

        return new PaymentInvoiceRequest(
            orderNumber: $order->order_number,
            amountHalalah: $payment->amount_halalah,
            callbackUrl: secure_url(($order->locale === 'en' ? '/en' : '').'/payments/paylink/callback'),
            cancelUrl: secure_url(($order->locale === 'en' ? '/en' : '').'/payments/paylink/cancel'),
            clientName: $order->user->name,
            clientEmail: $order->user->email,
            clientMobile: (string) $order->user->phone,
            products: $products,
        );
    }
}
