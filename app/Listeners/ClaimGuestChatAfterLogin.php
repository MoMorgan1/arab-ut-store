<?php

namespace App\Listeners;

use App\Actions\Chat\ClaimGuestChatConversations;
use App\Actions\Chat\ResolveChatOwner;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ClaimGuestChatAfterLogin
{
    public function __construct(
        private Request $request,
        private StatefulGuard $guard,
        private ResolveChatOwner $resolveChatOwner,
        private ClaimGuestChatConversations $claimGuestChatConversations,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        $guestOwners = $this->resolveChatOwner->existingGuestCandidatesForRequest($this->request);

        if ($guestOwners === []) {
            return;
        }

        try {
            $this->claimGuestChatConversations->execute($guestOwners, $event->user);
        } catch (Throwable $failure) {
            $this->guard->logout();

            throw $failure;
        }

        $this->request->session()->forget(ResolveChatOwner::SESSION_KEY);
    }
}
