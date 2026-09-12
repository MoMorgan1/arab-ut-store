<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Fulfillment\RecordSupplierPlacement;
use App\Enums\DeliveryPhase;
use App\Enums\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FulfillmentPlacementController extends Controller
{
    public function __invoke(Request $request, RecordSupplierPlacement $recordSupplierPlacement): JsonResponse
    {
        $validated = $request->validate([
            'order_item_public_id' => ['required', 'string', 'ulid'],
            'supplier' => ['required', 'string', Rule::in(Supplier::values())],
            'supplier_order_id' => ['required', 'string', 'max:255'],
            'delivery_phase' => ['sometimes', 'nullable', 'string', Rule::enum(DeliveryPhase::class)],
        ]);

        $result = $recordSupplierPlacement->execute($validated);

        return match ($result['outcome']) {
            'recorded', 'replayed' => response()->json([
                'data' => [
                    'acknowledged' => true,
                    'order_item_public_id' => (string) $validated['order_item_public_id'],
                    'supplier' => (string) $validated['supplier'],
                    'supplier_order_id' => (string) $validated['supplier_order_id'],
                    'job_public_id' => $result['jobPublicId'],
                ],
            ], 200)->header('Cache-Control', 'no-store'),
            'unknown_item' => $this->error('order_item_not_found', 'The referenced order item does not exist.', 404),
            'not_automated' => $this->error('service_not_automated', 'This service is not delivered through a supplier.', 422),
            'unpaid' => $this->error('order_item_unpaid', 'The order has not been paid.', 422),
            'supplier_reference_conflict' => $this->error('supplier_reference_conflict', 'This supplier order reference is already bound to another order item.', 409),
            'item_conflict' => $this->error('item_placement_conflict', 'This order item already has a different supplier placement.', 409),
            'placement_conflict' => $this->error('placement_conflict', 'The placement conflicted with a concurrent request. Retry.', 409),
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
