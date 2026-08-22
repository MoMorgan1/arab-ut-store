<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Checkout\RefundPaylinkOrder;
use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundPaylinkRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class PaylinkRefundController extends Controller
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function __invoke(
        RefundPaylinkRequest $request,
        Order $order,
        RefundPaylinkOrder $refundOrder,
    ): JsonResponse {
        $amount = (int) $request->validated('amountHalalah');

        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->error('authentication_required', 'Authentication is required.', 401);
        }

        if ($amount !== $order->total_halalah) {
            $this->recordStaffAudit->execute(
                actor: $actor,
                subject: $order,
                event: new StaffAuditEvent(
                    action: 'refunds.rejected',
                    metadata: [
                        'amount_halalah' => $amount,
                        'currency' => (string) $order->currency,
                        'provider' => 'paylink',
                        'failure_code' => 'full_refund_required',
                    ],
                    ipAddress: $request->ip(),
                ),
            );

            return $this->error('full_refund_required', 'Paylink supports a full original-payment refund only.', 422);
        }

        try {
            $refund = $refundOrder->execute(
                order: $order,
                reason: (string) $request->validated('reason'),
                actor: $actor,
                ipAddress: $request->ip(),
            );
        } catch (PaymentConfigurationException|PaymentGatewayException) {
            return $this->error('refund_provider_unavailable', 'The refund provider is unavailable.', 503);
        } catch (CheckoutUnavailable) {
            return $this->error('refund_unavailable', 'The order cannot be refunded automatically.', 409);
        }

        return response()->json(['data' => [
            'refundId' => $refund->public_id,
            'status' => $refund->status,
            'amountHalalah' => $refund->amount_halalah,
        ]])->header('Cache-Control', 'no-store, private');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => compact('code', 'message')], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
