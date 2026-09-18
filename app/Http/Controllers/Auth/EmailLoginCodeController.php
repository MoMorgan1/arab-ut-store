<?php

namespace App\Http\Controllers\Auth;

use App\Account\AccountOverviewUrl;
use App\Actions\Auth\SendEmailLoginCode;
use App\Actions\Auth\VerifyEmailLoginCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

final class EmailLoginCodeController extends Controller
{
    private const string SESSION_KEY = 'auth.email_login_code_for';

    /** The code screen, reachable only by the login attempt that opened it. */
    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $email = (string) $request->session()->get(self::SESSION_KEY, '');

        if ($email === '') {
            return redirect()->to($this->loginRoute($request));
        }

        return Inertia::render('auth/login-code', [
            // The same props every other auth page gets. This is the one auth
            // screen Fortify does not own, and a page without `authUi` renders
            // its own copy keys at the customer.
            ...app(FortifyServiceProvider::class, ['app' => app()])->authViewProps('login'),
            // Shown so the customer knows which inbox to open, and masked
            // because a screen somebody else can see should not read out their
            // whole address.
            'maskedEmail' => self::mask($email),
        ]);
    }

    public function store(
        Request $request,
        VerifyEmailLoginCode $verify,
        AccountOverviewUrl $accountOverviewUrl,
    ): RedirectResponse {
        $email = (string) $request->session()->get(self::SESSION_KEY, '');

        if ($email === '') {
            return redirect()->to($this->loginRoute($request));
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            $user = $verify->execute($email, (string) $validated['code']);
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'code' => trans('auth_ui.login.email_code_invalid'),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_KEY);

        Auth::login($user, remember: true);
        $request->session()->put('auth.identity_confirmed_at', now()->timestamp);

        return redirect()->intended($accountOverviewUrl->for($user));
    }

    /** A second code for the same address, when the first never arrived. */
    public function resend(Request $request, SendEmailLoginCode $send): Response
    {
        $email = (string) $request->session()->get(self::SESSION_KEY, '');

        if ($email === '') {
            return redirect()->to($this->loginRoute($request));
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->whereNull('password')
            ->first();

        $throttleKey = 'email-login-code:'.sha1($email);

        if ($user instanceof User && ! RateLimiter::tooManyAttempts($throttleKey, 6)) {
            RateLimiter::hit($throttleKey, 3600);
            $send->execute($user, $request->route('locale') === 'en' ? 'en' : 'ar');
        }

        // The same answer either way. A resend that is refused for the rate
        // limit and one that is sent look identical here on purpose: the
        // screen already told them a code is on its way, and the live code is
        // still valid.
        return back()->with('status', trans('auth_ui.login.email_code_sent'));
    }

    private function loginRoute(Request $request): string
    {
        return $request->route('locale') === 'en'
            ? route('localized.login', ['locale' => 'en'], absolute: false)
            : route('login', absolute: false);
    }

    /** `fahad@example.com` reads back as `fa****@example.com`. */
    public static function mask(string $email): string
    {
        $at = mb_strpos($email, '@');

        if ($at === false || $at < 1) {
            return '****';
        }

        $local = mb_substr($email, 0, $at);
        $domain = mb_substr($email, $at);
        $keep = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $keep.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($keep))).$domain;
    }
}
