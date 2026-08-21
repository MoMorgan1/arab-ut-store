<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\PrivateNoStore;
use App\Http\Responses\LocalizedFailedTwoFactorLoginResponse;
use App\Http\Responses\LocalizedPasswordResetResponse;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\RegisterResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
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
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(PasswordResetResponse::class, LocalizedPasswordResetResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(FailedTwoFactorLoginResponseContract::class, LocalizedFailedTwoFactorLoginResponse::class);
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
        $this->app->booted(fn () => $this->hardenTwoFactorManagementRoutes());
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

        Fortify::twoFactorChallengeView(function (Request $request) {
            $preferredLocale = User::query()
                ->whereKey($request->session()->get('login.id'))
                ->value('preferred_locale');

            $locale = $preferredLocale === 'en' ? 'en' : 'ar';
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

    private function hardenTwoFactorManagementRoutes(): void
    {
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
                'throttle:two-factor-management',
            ]);
        }
    }
}
