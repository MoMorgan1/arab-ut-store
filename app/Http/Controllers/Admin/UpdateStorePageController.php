<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\UpdateAdminStorePage as UpdateStorePageAction;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminStorePage as UpdateStorePageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

final class UpdateStorePageController extends Controller
{
    public function __invoke(
        UpdateStorePageRequest $request,
        string $key,
        UpdateStorePageAction $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingManage->value);

        $allowedPages = Config::array('store.simple_pages');
        abort_unless(in_array($key, $allowedPages, true), 404);

        /** @var array{ar: array{title: string, subtitle?: ?string, updatedLabel: string, blocks: list<array<string, mixed>>}, en: array{title: string, subtitle?: ?string, updatedLabel: string, blocks: list<array<string, mixed>>}} $validated */
        $validated = $request->validated();

        $page = $action->execute($actor, $key, $validated, $request->ip());

        return response()
            ->json([
                'page' => $page->key,
            ], 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
