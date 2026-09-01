<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class ConfirmTwoFactorController extends Controller
{
    public function create(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $currentRouteName = (string) $request->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->to(route($prefix.'settings', absolute: false));
        }

        if ($request->session()->has('auth.two_factor_confirmed_at')) {
            return redirect()->intended(route($prefix.'overview', absolute: false));
        }

        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/confirm-2fa', [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            'confirmUrl' => route($prefix.'confirm-2fa.store', absolute: false),
            'logoutUrl' => route('logout', absolute: false),
        ]);
    }

    public function store(Request $request, TwoFactorAuthenticationProvider $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $currentRouteName = (string) $request->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->to(route($prefix.'settings', absolute: false));
        }

        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $recoveryCode = $request->input('recovery_code');
        $code = $request->input('code');

        $isValid = false;
        $usedRecoveryCode = false;

        if (is_string($recoveryCode) && trim($recoveryCode) !== '') {
            $usedRecoveryCode = true;
            $trimmed = trim($recoveryCode);
            /** @var list<string> $recoveryCodes */
            $recoveryCodes = $user->recoveryCodes();

            /** @var string|null $matchedCode */
            $matchedCode = collect($recoveryCodes)->first(
                fn (string $c): bool => hash_equals($c, $trimmed)
            );

            if ($matchedCode !== null) {
                $user->replaceRecoveryCode($matchedCode);
                $isValid = true;
            }
        } elseif (is_string($code) && trim($code) !== '' && is_string($user->two_factor_secret)) {
            $cleanCode = str_replace([' ', '-'], '', trim($code));
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            $isValid = (bool) $provider->verify($secret, $cleanCode);
        }

        if (! $isValid) {
            $field = $usedRecoveryCode ? 'recovery_code' : 'code';
            $locale = $request->route('locale') === 'en' ? 'en' : 'ar';
            $messageKey = $usedRecoveryCode
                ? 'auth_ui.two_factor_challenge.invalid_recovery_code'
                : 'auth_ui.two_factor_challenge.invalid_code';

            throw ValidationException::withMessages([
                $field => trans($messageKey, locale: $locale),
            ]);
        }

        $request->session()->put('auth.two_factor_confirmed_at', now()->timestamp);

        return redirect()->intended(route($prefix.'overview', absolute: false));
    }
}
