<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminFaqEntry as UpdateFaqAction;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminFaqEntry as UpdateFaqRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class UpdateFaqEntryController extends Controller
{
    public function __invoke(
        UpdateFaqRequest $request,
        string $publicId,
        UpdateFaqAction $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        /** @var array{question_ar: string, question_en: string, answer_ar: string, answer_en: string} $validated */
        $validated = $request->validated();

        $entry = $action->execute($actor, $publicId, $validated, $request->ip());

        return response()
            ->json([
                'faq' => (string) $entry->public_id,
            ], 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
