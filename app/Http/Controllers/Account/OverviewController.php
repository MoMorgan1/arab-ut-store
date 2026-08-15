<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Account\Queries\ReadAccountOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function __construct(
        private readonly ReadAccountOverview $overview,
        private readonly AccountShell $shell,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        $locale = app()->getLocale();

        return Inertia::render('account/overview', [
            ...$this->shell->for($user, $locale),
            ...$this->overview->for($user, $locale),
        ]);
    }
}
