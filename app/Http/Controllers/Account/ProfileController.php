<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ProfileUpdateRequest;
use App\Models\User;
use App\Models\UserIdentityChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    public function __construct(private readonly AccountShell $shell) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $locale = app()->getLocale();
        $pending = UserIdentityChange::query()
            ->select(['id', 'user_id', 'kind', 'candidate_value', 'expires_at', 'consumed_at'])
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->get()
            ->keyBy('kind');

        return Inertia::render('account/profile', [
            ...$this->shell->for($user, $locale),
            'profile' => [
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'email' => [
                    'value' => $user->email,
                    'verified' => $user->email_verified_at !== null,
                    'pending' => $this->masked($pending->get(UserIdentityChange::KIND_EMAIL)),
                ],
                'phone' => [
                    'value' => $user->phone,
                    'verified' => $user->phone_verified_at !== null,
                    'pending' => $this->masked($pending->get(UserIdentityChange::KIND_PHONE)),
                ],
                'preferredLocale' => $user->preferred_locale,
                'displayCurrency' => $user->display_currency,
            ],
            'security' => [
                'passwordMode' => $this->hasPassword($user) ? 'change' : 'setup',
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ],
            'securityActions' => [
                'changePasswordUrl' => $this->route('account.security.password.change', $locale),
                'setupPasswordUrl' => $this->route('account.security.password.setup', $locale),
            ],
            'profileActions' => [
                'updateUrl' => $this->route('account.profile.update', $locale),
                'emailRequestUrl' => $this->route('account.profile.email.request', $locale),
                'phoneRequestUrl' => $this->route('account.profile.phone.request', $locale),
                'phoneConfirmUrl' => $this->route('account.profile.phone.confirm', $locale),
            ],
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $user->fill($request->safe()->only([
            'first_name',
            'last_name',
            'preferred_locale',
            'display_currency',
        ]))->save();
        $request->session()->put('display_currency', $user->display_currency);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('account.profile.saved'),
        ]);

        return redirect()->to($this->route('account.profile.show', app()->getLocale()));
    }

    private function masked(mixed $change): ?string
    {
        if (! $change instanceof UserIdentityChange) {
            return null;
        }

        $value = $change->candidate_value;

        if ($change->kind === UserIdentityChange::KIND_EMAIL) {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return '••••'.mb_substr($value, -4);
    }

    private function route(string $name, string $locale): string
    {
        return route($locale === 'en' ? 'localized.'.$name : $name, absolute: false);
    }

    private function hasPassword(User $user): bool
    {
        $hash = $user->getAttribute('password');

        return is_string($hash) && $hash !== '';
    }
}
