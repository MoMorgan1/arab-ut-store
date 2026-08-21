<?php

namespace App\Providers;

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Chat\ResolveChatOwner;
use App\Contracts\AI\AgentModelResolver;
use App\Contracts\AI\AgentSleeper;
use App\Contracts\AI\MonotonicClock;
use App\Services\AI\ConfiguredAgentModelResolver;
use App\Support\AI\SystemAgentSleeper;
use App\Support\AI\SystemMonotonicClock;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AgentModelResolver::class, ConfiguredAgentModelResolver::class);
        $this->app->singleton(MonotonicClock::class, SystemMonotonicClock::class);
        $this->app->bind(AgentSleeper::class, SystemAgentSleeper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('coins-cart', function (Request $request): Limit {
            $owner = app(ResolveCartOwner::class)->forRequest($request);

            return Limit::perMinute((int) config('coins.cart.rate_limit_per_minute'))
                ->by('coins-cart:'.$owner->idempotencyScope());
        });

        RateLimiter::for('account-identity-send', fn (Request $request): array => [
            Limit::perMinute(3)->by('account-identity-user:'.($request->user()?->getAuthIdentifier() ?? 'guest')),
            Limit::perMinute(3)->by('account-identity-candidate:'.hash('sha256', mb_strtolower(trim((string) ($request->input('email') ?? $request->input('phone')))))),
            Limit::perMinute(10)->by('account-identity-ip:'.$request->ip()),
        ]);
        RateLimiter::for('account-identity-confirm', fn (Request $request): array => [
            Limit::perMinute(10)->by('account-identity-confirm-user:'.($request->user()?->getAuthIdentifier() ?? 'guest')),
            Limit::perMinute(20)->by('account-identity-confirm-ip:'.$request->ip()),
        ]);

        RateLimiter::for('automation-catalog', function (Request $request): Limit {
            $identity = (string) ($request->header('X-ArabUT-Key') ?: $request->ip());

            return Limit::perMinute(10)
                ->by('automation-catalog:'.hash('sha256', $identity));
        });

        RateLimiter::for('automation-pricing', function (Request $request): Limit {
            $identity = (string) ($request->header('X-ArabUT-Key') ?: $request->ip());

            return Limit::perMinute(10)
                ->by('automation-pricing:'.hash('sha256', $identity));
        });

        RateLimiter::for('automation-sbc-pricing-read', function (Request $request): Limit {
            $identity = (string) ($request->header('X-ArabUT-Key') ?: $request->ip());

            return Limit::perMinute(10)
                ->by('automation-sbc-pricing-read:'.hash('sha256', $identity));
        });

        RateLimiter::for('paylink-webhook', fn (): Limit => Limit::perMinute(120)
            ->by('paylink-webhook'));

        RateLimiter::for('staff-payments', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('staff-payments:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('chat-conversations', function (Request $request): array {
            if (! config('chat.enabled', false)) {
                return [Limit::none()];
            }

            $owner = app(ResolveChatOwner::class)->forRequest($request);

            return [
                Limit::perMinute(10)->by('chat-conversations:'.$owner->idempotencyScope()),
                Limit::perMinute(30)->by('chat-conversations-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('chat-messages', function (Request $request): array {
            if (! config('chat.enabled', false)) {
                return [Limit::none()];
            }

            $owner = app(ResolveChatOwner::class)->forRequest($request);

            return [
                Limit::perMinute(30)->by('chat-messages:'.$owner->idempotencyScope()),
                Limit::perMinute(60)->by('chat-messages-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('chat-read', function (Request $request): array {
            if (! config('chat.enabled', false)) {
                return [Limit::none()];
            }

            $owner = app(ResolveChatOwner::class)->forRequest($request);

            return [
                Limit::perMinute(60)->by('chat-read:'.$owner->idempotencyScope()),
                Limit::perMinute(120)->by('chat-read-ip:'.$request->ip()),
            ];
        });
    }
}
