<?php

namespace App\Contracts\Payments;

use App\Payments\PaymentInvoice;
use App\Payments\PaymentInvoiceRequest;
use App\Payments\RefundResult;

interface PaymentGateway
{
    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoice;

    public function getInvoice(string $transactionNo): PaymentInvoice;

    public function cancelInvoice(string $transactionNo): void;

    public function refund(string $orderNumber, string $reason): RefundResult;
}
