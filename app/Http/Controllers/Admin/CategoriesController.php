<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCategoriesPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminCategories;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CategoriesController extends Controller
{
    public function __construct(private readonly AdminCategoriesPage $page) {}

    public function __invoke(ListAdminCategories $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $filters = $request->normalizedFilters();

        return Inertia::render('admin/categories/index', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $filters),
        ]);
    }
}
