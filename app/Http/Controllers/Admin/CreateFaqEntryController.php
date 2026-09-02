<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\CreateAdminFaqEntry;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminFaqEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CreateFaqEntryController extends Controller
{
    public function __invoke(StoreAdminFaqEntry $request, CreateAdminFaqEntry $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        /** @var array{question_ar: string, question_en: string, answer_ar: string, answer_en: string} $validated */
        $validated = $request->validated();

        $entry = $action->execute($actor, $validated, $request->ip());

        return response()
            ->json([
                'faq' => (string) $entry->public_id,
                'sort_order' => (int) $entry->sort_order,
            ], 201)
            ->header('Cache-Control', 'no-store, private');
    }
}
