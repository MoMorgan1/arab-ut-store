<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCustomerDetailPage;
use App\Admin\Queries\ReadAdminCustomerDetail;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerDetailController extends Controller
{
    public function __construct(
        private readonly ReadAdminCustomerDetail $customerDetailQuery,
        private readonly AdminCustomerDetailPage $page,
    ) {}

    public function __invoke(Request $request, string $publicId): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CustomersView->value);

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $result = $this->customerDetailQuery->findByPublicId($publicId, $actor);
        abort_if($result === null, 404);

        return Inertia::render('admin/customers/show', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $result),
        ]);
    }
}
