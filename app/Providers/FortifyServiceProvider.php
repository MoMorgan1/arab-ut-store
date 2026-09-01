<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\TrustedDeviceRegistry;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\PrivateNoStore;
use App\Http\Responses\LocalizedFailedTwoFactorLoginResponse;
use App\Http\Responses\LocalizedPasswordResetResponse;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(PasswordResetResponse::class, LocalizedPasswordResetResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(FailedTwoFactorLoginResponseContract::class, LocalizedFailedTwoFactorLoginResponse::class);

        // Fortify's default login pipeline resolves this contract, so binding it
        // here is all it takes to let a trusted device skip the TOTP challenge.
        $this->app->bind(RedirectsIfTwoFactorAuthenticatable::class, RedirectIfTwoFactorAuthenticatable::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasswordResetUrls();
        $this->revokeTrustedDevicesOnCredentialChange();
        $this->app->booted(function (): void {
            $this->hardenTwoFactorManagementRoutes();
            $this->hardenAuthRoutes();
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower(trim((string) $request->input(Fortify::username())));
            $password = (string) $request->input('password');

            if (! str_contains($email, '@')) {
                return null;
            }

            $user = User::query()->where('email', $email)->first();

            if ($user === null || ! $user->is_active || $user->password === null) {
                return null;
            }

            return Hash::check($password, $user->password) ? $user : null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            ...$this->authViewProps('login'),
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            ...$this->authViewProps('reset_password'),
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            ...$this->authViewProps('forgot_password'),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/register', [
            ...$this->authViewProps('register'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render(
            'auth/confirm-password',
            $this->authViewProps('confirm_password'),
        ));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            ...$this->authViewProps('verify_email'),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(function (Request $request) {
            $challengedUser = User::query()
                ->whereKey($request->session()->get('login.id'))
                ->first();

            // Owner decision (2026-08-21): the Admin surface is English-only.
            // Only privileged roles can enable TOTP, so their challenge renders
            // in English; every other challenged user keeps locale behavior.
            $locale = $challengedUser !== null
                && $challengedUser->role !== UserRole::Customer
                ? 'en'
                : ($challengedUser?->preferred_locale === 'en' ? 'en' : 'ar');
            app()->setLocale($locale);

            return Inertia::render(
                'auth/two-factor-challenge',
                [
                    ...$this->authViewProps('two_factor_challenge'),
                    'locale' => $locale,
                    'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
                ],
            );
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash('sha256', ($request->session()->get('login.id') ?? $request->session()->getId()).'|'.$request->ip())));

        RateLimiter::for('two-factor-management', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(hash('sha256', ($request->user()?->getAuthIdentifier() ?? $request->session()->getId()).'|'.$request->ip())));

        RateLimiter::for('whatsapp-login-send', fn (Request $request): Limit => Limit::perMinute(3)
            ->by(hash('sha256', (string) $request->input('phone').'|'.$request->ip())));
        RateLimiter::for('whatsapp-login-verify', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(hash('sha256', (string) $request->input('phone').'|'.$request->ip())));

        RateLimiter::for('verification-send', fn (Request $request): array => [
            Limit::perMinute(3)->by('verification-send-user:'.($request->user()?->getAuthIdentifier() ?? $request->session()->getId())),
            Limit::perMinute(10)->by('verification-send-ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('password-reset', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(3)->by(hash('sha256', $email.'|'.$request->ip()));
        });
    }

    /** @return array<string, mixed> */
    private function authViewProps(string $authPage): array
    {
        return [
            'authPage' => $authPage,
            'authUi' => trans('auth_ui'),
            'authRoutes' => $this->authRoutes(),
        ];
    }

    /** @return array<string, string> */
    private function authRoutes(): array
    {
        $localized = app()->getLocale() === 'en';
        $routeParameters = $localized ? ['locale' => 'en'] : [];
        $authUrl = fn (string $name): string => route(
            $localized ? "localized.{$name}" : $name,
            $routeParameters,
            absolute: false,
        );

        return [
            'homeUrl' => $localized ? route('localized.home', $routeParameters, absolute: false) : route('home', absolute: false),
            'loginUrl' => $authUrl('login'),
            'loginStoreUrl' => $authUrl('login.store'),
            'registerUrl' => $authUrl('register'),
            'registerStoreUrl' => $authUrl('register.store'),
            'forgotPasswordUrl' => $authUrl('password.request'),
            'forgotPasswordStoreUrl' => $authUrl('password.email'),
            'resetPasswordStoreUrl' => $authUrl('password.update'),
            'googleLoginUrl' => $this->googleConfigured()
                ? ($localized
                    ? route('localized.auth.google.redirect', ['locale' => 'en'], absolute: false)
                    : route('auth.google.redirect', absolute: false))
                : null,
            'whatsappSendUrl' => $localized
                ? route('localized.auth.whatsapp.send', ['locale' => 'en'], absolute: false)
                : route('auth.whatsapp.send', absolute: false),
            'whatsappVerifyUrl' => $localized
                ? route('localized.auth.whatsapp.verify', ['locale' => 'en'], absolute: false)
                : route('auth.whatsapp.verify', absolute: false),
        ];
    }

    private function googleConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function configurePasswordResetUrls(): void
    {
        ResetPasswordNotification::createUrlUsing(function (User $user, string $token): string {
            $localized = app()->getLocale() === 'en';
            $routeName = $localized ? 'localized.password.reset' : 'password.reset';
            $parameters = [
                ...($localized ? ['locale' => 'en'] : []),
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ];

            return url(route($routeName, $parameters, absolute: false));
        });
    }

    /**
     * A trusted device is a standing bypass of the TOTP challenge, so anything
     * that invalidates the second factor - or that account recovery would go
     * through - must drop every remembered device with it.
     */
    private function revokeTrustedDevicesOnCredentialChange(): void
    {
        $forget = function (object $event): void {
            $user = $event->user ?? null;

            if ($user instanceof User) {
                app(TrustedDeviceRegistry::class)->forgetAll($user);
            }
        };

        Event::listen(TwoFactorAuthenticationDisabled::class, $forget);
        Event::listen(TwoFactorAuthenticationConfirmed::class, $forget);
        Event::listen(RecoveryCodesGenerated::class, $forget);
        Event::listen(PasswordReset::class, $forget);
    }

    private function hardenTwoFactorManagementRoutes(): void
    {
        // Cached routes already contain this middleware and are loaded after
        // application booted callbacks have started running.
        if ($this->app->routesAreCached()) {
            return;
        }

        if (! Features::enabled(Features::twoFactorAuthentication())) {
            return;
        }

        $routeNames = [
            'two-factor.enable',
            'two-factor.confirm',
            'two-factor.disable',
            'two-factor.qr-code',
            'two-factor.secret-key',
            'two-factor.recovery-codes',
            'two-factor.regenerate-recovery-codes',
        ];

        Route::getRoutes()->refreshNameLookups();

        foreach ($routeNames as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            if ($route === null) {
                throw new \LogicException("Enabled Fortify route [{$routeName}] is missing.");
            }

            $route->middleware([
                PrivateNoStore::class,
                EnsureActiveUser::class,
                'throttle:two-factor-management',
            ]);
        }
    }

    private function hardenAuthRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::getRoutes()->refreshNameLookups();

        if (Features::enabled(Features::registration())) {
            $registerRoute = Route::getRoutes()->getByName('register.store');

            if ($registerRoute !== null) {
                $registerRoute->middleware(['throttle:register']);
            }
        }

        if (Features::enabled(Features::resetPasswords())) {
            $passwordEmailRoute = Route::getRoutes()->getByName('password.email');

            if ($passwordEmailRoute !== null) {
                $passwordEmailRoute->middleware(['throttle:password-reset']);
            }

            $passwordUpdateRoute = Route::getRoutes()->getByName('password.update');

            if ($passwordUpdateRoute !== null) {
                $passwordUpdateRoute->middleware(['throttle:password-reset']);
            }
        }
    }
}
