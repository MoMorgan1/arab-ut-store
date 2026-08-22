<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCustomersPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminCustomers;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CustomersController extends Controller
{
    public function __construct(private readonly AdminCustomersPage $page) {}

    public function __invoke(ListAdminCustomers $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CustomersView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $filters = $request->normalizedFilters();

        return Inertia::render('admin/customers/index', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $filters),
        ]);
    }
}
