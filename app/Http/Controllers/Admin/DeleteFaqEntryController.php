<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\DeleteAdminFaqEntry;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DeleteFaqEntryController extends Controller
{
    public function __invoke(
        Request $request,
        string $publicId,
        DeleteAdminFaqEntry $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $action->execute(
            actor: $actor,
            faqEntryPublicId: $publicId,
            ipAddress: $request->ip(),
        );

        return response()
            ->json([
                'deleted' => true,
                'id' => $publicId,
            ], 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
