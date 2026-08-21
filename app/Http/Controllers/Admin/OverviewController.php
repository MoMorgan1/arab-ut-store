<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminOverviewPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function __construct(private readonly AdminOverviewPage $page) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'integer', Rule::in([7, 30])],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::authorize(AdminPermission::DashboardView->value);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/overview', [
            'auth' => null,
            ...$this->page->for($actor, $locale, (int) ($validated['range'] ?? 7)),
        ]);
    }
}
