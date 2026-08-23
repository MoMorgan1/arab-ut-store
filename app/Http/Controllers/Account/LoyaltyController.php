<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Account\Queries\ReadLoyaltyOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LoyaltyController extends Controller
{
    public function __construct(
        private readonly ReadLoyaltyOverview $overview,
        private readonly AccountShell $shell,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        $locale = app()->getLocale();

        return Inertia::render('account/loyalty', [
            ...$this->shell->for($user, $locale),
            ...$this->overview->for($user, $locale),
        ]);
    }
}
