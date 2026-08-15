<?php

namespace App\Http\Controllers\Account;

use App\Account\Queries\ReadAccountOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function __construct(private readonly ReadAccountOverview $overview) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return Inertia::render('account/overview', [
            'accountUi' => trans('account'),
            'accountIdentity' => [
                'name' => $user->name,
                'greeting' => trans('account.greeting', ['name' => $user->name]),
            ],
            'accountNavigation' => [[
                'key' => 'overview',
                'label' => trans('account.navigation.overview'),
                'url' => route(
                    app()->getLocale() === 'en' ? 'localized.account.overview' : 'account.overview',
                    absolute: false,
                ),
            ]],
            'logoutUrl' => route('logout', absolute: false),
            ...$this->overview->for($user, app()->getLocale()),
        ]);
    }
}
