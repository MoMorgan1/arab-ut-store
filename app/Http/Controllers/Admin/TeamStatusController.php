<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateStaffStatus;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStaffStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class TeamStatusController extends Controller
{
    public function __construct(
        private readonly UpdateStaffStatus $action,
    ) {}

    public function __invoke(UpdateStaffStatusRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::StaffManage->value);

        $target = $this->action->execute(
            actor: $actor,
            staffPublicId: $publicId,
            action: $request->action(),
            expectedActive: $request->expectedActive(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'isActive' => (bool) $target->is_active,
                'updatedAt' => $target->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
