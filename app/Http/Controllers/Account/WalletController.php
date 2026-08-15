<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Account\Queries\ReadWalletLedger;
use App\Account\Queries\ResolveLoyaltyProgress;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WalletController extends Controller
{
    public function __construct(
        private readonly ReadWalletLedger $ledger,
        private readonly ResolveLoyaltyProgress $loyalty,
        private readonly AccountShell $shell,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $locale = app()->getLocale();

        return Inertia::render('account/wallet', [
            ...$this->shell->for($user, $locale),
            ...$this->ledger->for($user, $locale),
            'loyalty' => $this->loyalty->for($user, $locale),
        ]);
    }
}
