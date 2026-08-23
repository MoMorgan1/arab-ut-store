<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateStaffRole;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStaffRoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class TeamRoleController extends Controller
{
    public function __construct(
        private readonly UpdateStaffRole $action,
    ) {}

    public function __invoke(UpdateStaffRoleRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::StaffManage->value);

        $target = $this->action->execute(
            actor: $actor,
            staffPublicId: $publicId,
            newRole: $request->role(),
            expectedRole: $request->expectedRole(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'role' => $target->role->value,
                'updatedAt' => $target->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
