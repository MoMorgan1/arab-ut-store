<?php

namespace App\Http\Controllers\Account;

use App\Account\Actions\SetAccountPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\PasswordChangeRequest;
use App\Http\Requests\Account\PasswordSetupRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class SecurityController extends Controller
{
    public function show(): RedirectResponse
    {
        return redirect()->to($this->route('account.profile.show', app()->getLocale()));
    }

    public function change(
        PasswordChangeRequest $request,
        SetAccountPassword $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $action->change($user, $request->validated('password'));

        return $this->success();
    }

    public function setup(
        PasswordSetupRequest $request,
        SetAccountPassword $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $action->setup($user, $request, $request->validated('password'));

        return $this->success();
    }

    private function success(): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('account.security.password_changed'),
        ]);

        return redirect()->to($this->route('account.profile.show', app()->getLocale()));
    }

    private function route(string $name, string $locale): string
    {
        return route($locale === 'en' ? 'localized.'.$name : $name, absolute: false);
    }
}
