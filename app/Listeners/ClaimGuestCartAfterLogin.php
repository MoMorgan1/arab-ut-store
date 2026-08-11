<?php

namespace App\Listeners;

use App\Actions\Cart\ClaimGuestCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class ClaimGuestCartAfterLogin
{
    public function __construct(
        private Request $request,
        private ResolveCartOwner $resolveCartOwner,
        private ClaimGuestCart $claimGuestCart,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        $guestOwner = $this->resolveCartOwner->existingGuestForRequest($this->request);
        $guestSessionHmac = $guestOwner?->sessionKey();

        if ($guestSessionHmac === null) {
            return;
        }

        $this->claimGuestCart->execute($guestSessionHmac, $event->user);
        $session = $this->request->session();

        DB::afterCommit(
            fn () => $session->forget(ResolveCartOwner::SESSION_KEY),
        );
    }
}
