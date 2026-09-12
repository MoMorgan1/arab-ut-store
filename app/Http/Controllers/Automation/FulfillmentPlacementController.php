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
            'delivery_phase' => ['required', 'string', Rule::enum(DeliveryPhase::class)],
            'challenge_ids' => ['sometimes'],
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
            'service_has_no_challenge' => $this->error('service_has_no_challenge', 'This order item has no challenge to solve.', 422),
            'supplier_cannot_solve_challenges' => $this->error('supplier_cannot_solve_challenges', 'This supplier does not solve challenges.', 422),
            'unpaid' => $this->error('order_item_unpaid', 'The order has not been paid.', 422),
            'challenge_ids_required' => $this->error('challenge_ids_required', 'Challenge placements must include at least one challenge id.', 422),
            'challenge_ids_not_permitted' => $this->error('challenge_ids_not_permitted', 'Coins placements cannot accept challenge ids.', 422),
            'invalid_challenge_ids' => $this->error(
                'invalid_challenge_ids',
                sprintf(
                    '%d challenge %s invalid.',
                    $result['invalidCount'] ?? 1,
                    ($result['invalidCount'] ?? 1) === 1 ? 'id is' : 'ids are',
                ),
                422,
            ),
            'supplier_reference_conflict' => $this->error('supplier_reference_conflict', 'This supplier order reference is already recorded on another placement.', 409),
            'item_conflict' => $this->error('item_placement_conflict', 'This order item already has a different placement for this phase.', 409),
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
