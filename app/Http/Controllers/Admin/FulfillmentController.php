<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminFulfillmentPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminFulfillment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class FulfillmentController extends Controller
{
    public function __construct(private readonly AdminFulfillmentPage $page) {}

    public function __invoke(ListAdminFulfillment $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::FulfillmentView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/fulfillment/index', [
            'auth' => null,
            ...$this->page->for(
                $actor,
                $locale,
                $request->normalizedFilters(),
                withCost: $request->canSeeCost(),
                canAct: $request->canAct(),
            ),
        ]);
    }
}
