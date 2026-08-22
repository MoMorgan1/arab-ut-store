<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminOrdersPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminOrders;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class OrdersController extends Controller
{
    public function __construct(private readonly AdminOrdersPage $page) {}

    public function __invoke(ListAdminOrders $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::OrdersView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $filters = $request->normalizedFilters();

        return Inertia::render('admin/orders/index', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $filters),
        ]);
    }
}
