<?php

namespace App\Http\Middleware;

use App\Actions\Auth\SendEmailLoginCode;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catches the sign-in that could only ever fail, and turns it into one that works.
 *
 * `ImportSallaCustomers` wrote `password => null` for every imported customer
 * and verified none of their addresses. Such an account cannot be signed into -
 * `authenticateUsing` returns null the moment it sees a null password - and
 * cannot be recovered either, because the reset door checks
 * `email_verified_at`. Whatever these customers typed, the store answered
 * "those details do not match", which was true and useless: there are no
 * details that would have matched.
 *
 * So when the address belongs to an active account with no password, the store
 * says so and sends a code instead. No password is ever checked, which is why
 * this runs before Fortify rather than inside it: there is nothing to compare.
 *
 * **This tells the sender that the address is registered**, which the generic
 * "details do not match" did not. That is a deliberate trade and the owner's
 * call, 2026-09-18. Withholding it is what left a whole cohort locked out and
 * told twice that help was on the way; the code goes to the account's own
 * address, so what an attacker gains is the knowledge that an address is a
 * customer here - which registration leaks anyway.
 */
final class OfferEmailLoginCode
{
    public function __construct(private readonly SendEmailLoginCode $send) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Both doors, because both were shut. Login answered "those details do
        // not match" and recovery answered "check your inbox" without sending
        // anything; a customer who tried one and then the other was turned
        // away twice. They now lead to the same place.
        $isLogin = $request->routeIs('login.store', 'localized.login.store');
        $isRecovery = $request->routeIs('password.email', 'localized.password.email');

        if (! $request->isMethod('POST') || (! $isLogin && ! $isRecovery)) {
            return $next($request);
        }

        $email = mb_strtolower(trim((string) $request->input(Fortify::username())));

        if ($email === '' || ! str_contains($email, '@')) {
            return $next($request);
        }

        // Three conditions, and all three are load-bearing. A null password
        // alone is NOT the import cohort: when Google claims an account
        // somebody else pre-registered with an address they do not own, the
        // store nulls that password on purpose, to evict the attacker's
        // credentials. Offering a code there would hand the evicted attacker a
        // button that mails the real owner - so the unverified address and the
        // absent social account are what separate "never had a password" from
        // "had one taken away". A customer with Google linked already has a
        // door, and it is a better one than this.
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->whereNull('password')
            ->whereNull('email_verified_at')
            ->whereDoesntHave('socialAccounts')
            ->first();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Keyed on the address rather than the caller: the point is to stop
        // one mailbox being flooded, and the caller is not who would suffer.
        // Six an hour leaves room for a customer who mistypes and retries
        // without leaving the door open to a mail bomb.
        $throttleKey = 'email-login-code:'.sha1($email);

        if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
            // Say so. Showing the code screen here would be the same lie this
            // whole change exists to remove: a customer told a code is coming
            // when none is, waiting for mail that will never arrive. Whoever
            // spent the allowance - them or somebody who knows their address -
            // the honest answer is "not now".
            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth_ui.login.email_code_throttled')],
            ]);
        }

        // Charged only when a code actually goes out. `execute` declines a
        // second code within the minute, and charging for that decline is how
        // somebody who knows an address could spend the whole hour's worth in
        // a few seconds and leave the owner locked out of their own door.
        if ($this->send->execute($user, $request->route('locale') === 'en' ? 'en' : 'ar')) {
            RateLimiter::hit($throttleKey, 3600);
        }

        // The address is remembered in the session, never in the URL: it is
        // the customer's own, and a query string is copied into logs, history
        // and whatever sits in front of the app.
        $request->session()->put('auth.email_login_code_for', $email);

        $route = $request->route('locale') === 'en'
            ? route('localized.login.code', ['locale' => 'en'], absolute: false)
            : route('login.code', absolute: false);

        return redirect()->to($route);
    }
}
