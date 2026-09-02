<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\MoveAdminFaqEntry as MoveFaqAction;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveAdminFaqEntry as MoveFaqRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class MoveFaqEntryController extends Controller
{
    public function __invoke(
        MoveFaqRequest $request,
        string $publicId,
        MoveFaqAction $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $entry = $action->execute(
            actor: $actor,
            faqEntryPublicId: $publicId,
            direction: $request->direction(),
            ipAddress: $request->ip(),
        );

        return response()
            ->json([
                'faq' => (string) $entry->public_id,
                'sort_order' => (int) $entry->sort_order,
            ], 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
