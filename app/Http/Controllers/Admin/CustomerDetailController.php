<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminCustomerDetailPage;
use App\Admin\Queries\ReadAdminCustomerDetail;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PublicHandle\CustomerHandle;
use Illuminate\Http\RedirectResponse;
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

    public function __invoke(Request $request, string $customer): Response|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::CustomersView->value);

        $user = CustomerHandle::resolveForAdmin($customer);

        // A request addressed by the internal ULID is sent to the short-number
        // URL when the account has a number. The resolved model is reused below
        // so the handle is not resolved a second time.
        if (CustomerHandle::isUlid($customer)) {
            $redirect = CustomerHandle::legacyRedirect($request, $user);

            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }
        }

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
        $result = $this->customerDetailQuery->forUser($user, $actor);
        abort_if($result === null, 404);

        return Inertia::render('admin/customers/show', [
            'auth' => null,
            ...$this->page->for($actor, $locale, $result),
        ]);
    }
}
