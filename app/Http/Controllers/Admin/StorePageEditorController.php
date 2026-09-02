<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Presenters\AdminStorePageEditorPage;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class StorePageEditorController extends Controller
{
    public function __construct(private readonly AdminStorePageEditorPage $page) {}

    public function __invoke(Request $request, string $key): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize(AdminPermission::MarketingView->value);

        $allowedPages = Config::array('store.simple_pages');
        abort_unless(in_array($key, $allowedPages, true), 404);

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/marketing/page-editor', [
            'auth' => null,
            ...$this->page->for($actor, $key, $locale),
        ]);
    }
}
