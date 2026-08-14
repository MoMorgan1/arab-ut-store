<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Checkout\ReconcilePaylinkPayment;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaylinkWebhookController extends Controller
{
    public function __invoke(Request $request, ReconcilePaylinkPayment $reconcile): JsonResponse
    {
        $validated = $request->validate([
            'transactionNo' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
        ]);

        $payment = Payment::query()
            ->where('provider', 'paylink')
            ->where('provider_payment_id', $validated['transactionNo'])
            ->first();

        if ($payment instanceof Payment) {
            $reconcile->execute($payment);
        }

        return response()->json(['data' => ['acknowledged' => true]]);
    }
}
