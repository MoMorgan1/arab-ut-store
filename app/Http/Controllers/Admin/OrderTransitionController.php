<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\TransitionAdminOrder;
use App\Admin\Presenters\AdminOrderDetail;
use App\Admin\Queries\ReadAdminOrderDetail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransitionAdminOrder as TransitionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class OrderTransitionController extends Controller
{
    public function __construct(
        private readonly TransitionAdminOrder $action,
        private readonly ReadAdminOrderDetail $query,
        private readonly AdminOrderDetail $presenter,
    ) {}

    public function __invoke(TransitionRequest $request, string $order): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $targetStatus = $request->targetStatus();
        $expectedStatus = $request->expectedStatus();

        $model = $this->action->execute(
            actor: $actor,
            orderHandle: $order,
            targetStatus: $targetStatus,
            expectedStatus: $expectedStatus,
            reason: $request->reason(),
            note: $request->note(),
        );

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $detail = $this->query->findByHandle((string) $model->getAttribute('order_number'), $actor);
        $presented = $detail !== null
            ? $this->presenter->present($detail['order'], $locale, $detail['auditLogs'])
            : [
                'id' => (string) $model->getAttribute('order_number'),
                'status' => $model->status->value,
            ];

        return response()->json([
            'order' => $presented,
            'status' => $model->status->value,
        ], 200);
    }
}
