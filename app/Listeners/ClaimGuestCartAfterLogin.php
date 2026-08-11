<?php

namespace App\Listeners;

use App\Actions\Cart\ClaimGuestCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ClaimGuestCartAfterLogin
{
    public function __construct(
        private Request $request,
        private StatefulGuard $guard,
        private ResolveCartOwner $resolveCartOwner,
        private ClaimGuestCart $claimGuestCart,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        $guestOwners = $this->resolveCartOwner->existingGuestCandidatesForRequest($this->request);

        if ($guestOwners === []) {
            return;
        }

        try {
            $this->claimGuestCart->execute($guestOwners, $event->user);
        } catch (Throwable $failure) {
            $this->guard->logout();

            throw $failure;
        }
        $session = $this->request->session();

        DB::afterCommit(
            fn () => $session->forget(ResolveCartOwner::SESSION_KEY),
        );
    }
}
