<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\SetAdminReviewVisibility;
use App\Enums\AdminPermission;
use App\Exceptions\AdminReviewVisibilityConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetAdminReviewVisibility as SetAdminReviewVisibilityRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ReviewVisibilityController extends Controller
{
    public function __construct(
        private readonly SetAdminReviewVisibility $action,
    ) {}

    public function __invoke(SetAdminReviewVisibilityRequest $request, string $publicId): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        try {
            $review = $this->action->execute(
                actor: $actor,
                reviewPublicId: $publicId,
                visible: $request->visible(),
                expectedVisible: $request->expectedVisible(),
                ipAddress: $request->ip(),
            );
        } catch (AdminReviewVisibilityConflict $exception) {
            return response()->json([
                'review' => $exception->reviewPublicId,
                'current' => ['visible' => $exception->currentVisible],
            ], 409)
                ->header('Cache-Control', 'no-store, private')
                ->header('Content-Type', 'application/json');
        }

        return response()->json([
            'review' => (string) $review->public_id,
            'visible' => (bool) $review->is_visible,
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
