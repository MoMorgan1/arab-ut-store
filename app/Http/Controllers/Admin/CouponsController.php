<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCouponsPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CouponsController extends Controller
{
    public function __construct(private readonly AdminCouponsPage $page) {}

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        /** @var array{search?: ?string, status?: ?string, scope?: ?string, discount_type?: ?string, sort?: string, direction?: string, per_page?: int, page?: int} $filters */
        $filters = array_filter([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'scope' => $request->query('scope'),
            'discount_type' => $request->query('discount_type'),
            'sort' => $request->query('sort', 'created_at'),
            'direction' => $request->query('direction', 'desc'),
            'per_page' => (int) $request->query('per_page', '15'),
            'page' => (int) $request->query('page', '1'),
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        return Inertia::render('admin/marketing/coupons', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $filters),
        ]);
    }
}
