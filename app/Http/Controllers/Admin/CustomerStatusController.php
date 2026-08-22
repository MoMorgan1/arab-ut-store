<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminCustomerStatus;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminCustomerStatus as UpdateStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CustomerStatusController extends Controller
{
    public function __construct(
        private readonly UpdateAdminCustomerStatus $action,
    ) {}

    public function __invoke(UpdateStatusRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CustomersUpdateStatus->value);

        $user = $this->action->execute(
            actor: $actor,
            customerPublicId: $publicId,
            action: $request->action(),
            reasonCode: $request->reasonCode(),
            caseReference: $request->caseReference(),
            expectedActive: $request->expectedActive(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'isActive' => (bool) $user->is_active,
                'updatedAt' => $user->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
