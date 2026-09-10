<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminProductDetailPage;
use App\Admin\Queries\ReadAdminProductDetail;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublicHandle\ProductHandle;
use Illuminate\Http\RedirectResponse;
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

    public function __invoke(Request $request, string $product): Response|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CatalogView->value);

        $target = ProductHandle::resolveForAdmin($product);

        // A request addressed by the internal ULID is sent to the slug URL. The
        // resolved model is reused below so the handle is not resolved twice.
        if (ProductHandle::isUlid($product)) {
            $redirect = ProductHandle::legacyRedirect($request, $target);

            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }
        }

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $result = $this->productDetailQuery->forProduct($target, $actor);

        return Inertia::render('admin/products/show', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $result),
        ]);
    }
}
