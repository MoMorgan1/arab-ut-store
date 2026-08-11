<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LocalizedPasswordResetResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PasswordResetResponse::class, LocalizedPasswordResetResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configurePasswordResetUrls();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
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
        ];
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
}
