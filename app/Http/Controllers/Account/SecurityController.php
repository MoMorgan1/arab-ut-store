<?php

namespace App\Http\Controllers\Account;

use App\Account\Actions\SetAccountPassword;
use App\Account\Presenters\AccountShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\PasswordChangeRequest;
use App\Http\Requests\Account\PasswordSetupRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class SecurityController extends Controller
{
    public function __construct(private readonly AccountShell $shell) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $locale = app()->getLocale();
        $hasPassword = $this->hasPassword($user);
        $emailRecovery = $user->email_verified_at !== null;

        return Inertia::render('account/security', [
            ...$this->shell->for($user, $locale),
            'security' => [
                'passwordMode' => $hasPassword ? 'change' : 'setup',
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'recoveryMode' => $emailRecovery ? 'email' : 'whatsapp',
                'recoveryUrl' => $emailRecovery
                    ? route(
                        $locale === 'en' ? 'localized.password.request' : 'password.request',
                        $locale === 'en' ? ['locale' => 'en'] : [],
                        absolute: false,
                    )
                    : (string) config('store.support.whatsapp_url'),
            ],
            'securityActions' => [
                'changePasswordUrl' => $this->route('account.security.password.change', $locale),
                'setupPasswordUrl' => $this->route('account.security.password.setup', $locale),
            ],
        ]);
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

        return redirect()->to($this->route('account.security.show', app()->getLocale()));
    }

    private function hasPassword(User $user): bool
    {
        $hash = $user->getAttribute('password');

        return is_string($hash) && $hash !== '';
    }

    private function route(string $name, string $locale): string
    {
        return route($locale === 'en' ? 'localized.'.$name : $name, absolute: false);
    }
}
