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
            ...$this->overview->for($user, app()->getLocale()),
        ]);
    }
}
