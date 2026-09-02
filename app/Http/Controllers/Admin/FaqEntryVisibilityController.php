<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SetAdminFaqEntryVisibility as SetVisibilityAction;
use App\Enums\AdminPermission;
use App\Exceptions\AdminFaqEntryVisibilityConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminFaqEntryVisibility as SetVisibilityRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class FaqEntryVisibilityController extends Controller
{
    public function __invoke(
        SetVisibilityRequest $request,
        string $publicId,
        SetVisibilityAction $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        try {
            $entry = $action->execute(
                actor: $actor,
                faqEntryPublicId: $publicId,
                visible: $request->visible(),
                expectedVisible: $request->expectedVisible(),
                ipAddress: $request->ip(),
            );

            return response()
                ->json([
                    'faq' => (string) $entry->public_id,
                    'visible' => (bool) $entry->is_visible,
                ], 200)
                ->header('Cache-Control', 'no-store, private');
        } catch (AdminFaqEntryVisibilityConflict $exception) {
            return response()
                ->json([
                    'faq' => $exception->faqEntryPublicId,
                    'current' => [
                        'visible' => $exception->currentVisible,
                    ],
                ], 409)
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
