<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminProductsPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminProducts;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ProductsController extends Controller
{
    public function __construct(private readonly AdminProductsPage $page) {}

    public function __invoke(ListAdminProducts $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        $filters = $request->normalizedFilters();

        return Inertia::render('admin/products/index', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $filters),
        ]);
    }
}
