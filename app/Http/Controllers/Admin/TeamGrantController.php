<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\GrantStaffAccess;
use App\Enums\AdminPermission;
use App\Exceptions\AdminStaffGrantRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantStaffAccessRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class TeamGrantController extends Controller
{
    public function __construct(
        private readonly GrantStaffAccess $action,
    ) {}

    public function __invoke(GrantStaffAccessRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::StaffManage->value);

        try {
            $target = $this->action->execute(
                actor: $actor,
                email: $request->email(),
                newRole: $request->role(),
                ipAddress: $request->ip(),
            );
        } catch (AdminStaffGrantRejected $exception) {
            return response()->json([
                'reason' => $exception->reason,
            ], 422)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'data' => [
                'id' => (string) $target->public_id,
                'name' => (string) $target->name,
                'email' => (string) $target->email,
                'role' => $target->role->value,
            ],
        ], 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
