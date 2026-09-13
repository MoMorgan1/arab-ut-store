<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\ManualOrderOptions;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The choices the manual-order drawer offers, read when it opens.
 *
 * Gated on `orders.create` rather than `catalog.view`: support staff may write
 * a manual order (owner, 2026-09-13) and hold no catalogue permission, so
 * gating on the catalogue would have made the feature unusable for the role it
 * was granted to. What comes back is only what a picker needs - names, prices
 * and platforms - and nothing the catalogue screen exists to manage.
 */
final class ManualOrderOptionsController extends Controller
{
    public function __construct(
        private readonly ManualOrderOptions $options,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::OrdersCreate->value);

        return response()
            ->json(['data' => $this->options->for($request->route('locale') === 'en' ? 'en' : 'ar')])
            ->header('Cache-Control', 'no-store, private');
    }
}
