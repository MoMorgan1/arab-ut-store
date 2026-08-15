<?php

namespace App\Http\Controllers\Account;

use App\Account\Actions\ConfirmEmailChange;
use App\Account\Actions\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIdentityChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ProfileEmailController extends Controller
{
    public function store(Request $request, RequestEmailChange $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'current_password' => ['nullable', 'string'],
        ]);
        $action->execute(
            $user,
            $request,
            $validated['email'],
            $validated['current_password'] ?? null,
        );

        return redirect()->to($this->profileUrl());
    }

    public function confirm(
        Request $request,
        string $change,
        ConfirmEmailChange $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $pending = UserIdentityChange::query()
            ->where('public_id', $change)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $token = $request->query('token');

        if (! is_string($token)) {
            abort(403);
        }

        $action->execute($user, $pending, $token, app()->getLocale());

        return redirect()->to($this->profileUrl());
    }

    private function profileUrl(): string
    {
        return route(
            app()->getLocale() === 'en' ? 'localized.account.profile.show' : 'account.profile.show',
            absolute: false,
        );
    }
}
