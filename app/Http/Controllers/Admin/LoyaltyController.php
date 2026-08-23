<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminLoyaltyPage;
use App\Admin\Queries\ReadAdminLoyalty;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class LoyaltyController extends Controller
{
    public function __construct(
        private readonly ReadAdminLoyalty $query,
        private readonly AdminLoyaltyPage $page,
    ) {}

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::LoyaltyView->value);

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $data = $this->query->get();

        return Inertia::render('admin/marketing/loyalty', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $data),
        ]);
    }
}
