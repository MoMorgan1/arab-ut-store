<?php

namespace App\Http\Controllers\Auth;

use App\Account\AccountOverviewUrl;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class GoogleAuthenticationController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless($this->configured(), 503);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, AccountOverviewUrl $accountOverviewUrl): RedirectResponse
    {
        abort_unless($this->configured(), 503);

        try {
            /** @var SocialiteUser $providerUser */
            $providerUser = Socialite::driver('google')->user();
            $user = $this->resolveUser($providerUser, $request->route('locale') === 'en' ? 'en' : 'ar');
        } catch (Throwable) {
            return redirect($this->loginUrl($request))->withErrors([
                'email' => trans('auth_ui.login.google_error'),
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended($accountOverviewUrl->for($user));
    }

    private function resolveUser(SocialiteUser $providerUser, string $locale): User
    {
        $providerId = trim((string) $providerUser->getId());
        $email = Str::lower(trim((string) $providerUser->getEmail()));
        $verified = filter_var($providerUser->user['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if ($providerId === '' || $email === '' || ! $verified) {
            throw new \DomainException('Google identity is incomplete or unverified.');
        }

        return DB::transaction(function () use ($email, $locale, $providerId, $providerUser): User {
            $socialAccount = SocialAccount::query()
                ->where('provider', 'google')
                ->where('provider_user_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($socialAccount !== null) {
                $user = $socialAccount->user()->lockForUpdate()->firstOrFail();

                if (! $user->is_active || $user->role !== UserRole::Customer) {
                    throw new \DomainException('User is inactive.');
                }

                return $user;
            }

            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if ($user !== null && (! $user->is_active || $user->role !== UserRole::Customer)) {
                throw new \DomainException('User is inactive.');
            }

            if ($user === null) {
                [$firstName, $lastName] = $this->splitName((string) $providerUser->getName());
                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => null,
                    'preferred_locale' => $locale,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->socialAccounts()->create([
                'provider' => 'google',
                'provider_user_id' => $providerId,
                'provider_email' => $email,
            ]);

            return $user;
        }, attempts: 3);
    }

    /** @return array{string, string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [$parts[0] ?? 'Google', $parts[1] ?? 'Customer'];
    }

    private function loginUrl(Request $request): string
    {
        return $request->route('locale') === 'en'
            ? route('localized.login', ['locale' => 'en'], absolute: false)
            : route('login', absolute: false);
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
