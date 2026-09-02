<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminFaqPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class FaqController extends Controller
{
    public function __construct(private readonly AdminFaqPage $page) {}

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/marketing/faq', [
            'auth' => null,
            ...$this->page->for($actor, $locale),
        ]);
    }
}
