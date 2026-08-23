<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminProductDetailPage;
use App\Admin\Queries\ReadAdminProductDetail;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ProductDetailController extends Controller
{
    public function __construct(
        private readonly ReadAdminProductDetail $productDetailQuery,
        private readonly AdminProductDetailPage $page,
    ) {}

    public function __invoke(Request $request, string $publicId): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogView->value);

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $result = $this->productDetailQuery->findByPublicId($publicId, $actor);
        abort_if($result === null, 404);

        return Inertia::render('admin/products/show', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $result),
        ]);
    }
}
