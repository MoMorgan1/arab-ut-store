<?php

namespace App\Providers;

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Chat\ResolveChatOwner;
use App\Contracts\AI\AgentModelResolver;
use App\Contracts\AI\AgentSleeper;
use App\Contracts\AI\MonotonicClock;
use App\Services\AI\ConfiguredAgentModelResolver;
use App\Support\AI\AgentRuntimeConfig;
use App\Support\AI\SystemAgentSleeper;
use App\Support\AI\SystemMonotonicClock;
use App\View\Components\InertiaApp;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
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

        // Emits the Inertia page payload as UTF-8 rather than escape sequences.
        Blade::component('inertia-app', InertiaApp::class);
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
        RateLimiter::for('account-tracking-refresh', fn (Request $request): array => [
            Limit::perMinute(30)->by('account-tracking-refresh-user:'.($request->user()?->getAuthIdentifier() ?? 'guest')),
            Limit::perMinute(10)->by('account-tracking-refresh-order:'.(string) $request->route('order')),
        ]);
        // Tighter than the refresh limiter: an action is a supplier request on the
        // long (5s/12s) timeout profile, so a single order may fire at most three a
        // minute and one customer five a minute across all of their orders.
        // Both buckets carry the caller. Throttling runs before the controller can
        // check ownership, so a bucket keyed on the order handle alone is one any
        // signed-in visitor can empty by naming someone else's order number: the
        // requests 404, and the owner is locked out of correcting their details
        // for the minute. Order numbers are sequential, which makes that easy.
        RateLimiter::for('account-tracking-action', function (Request $request): array {
            $caller = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(5)->by('account-tracking-action-user:'.$caller),
                Limit::perMinute(3)->by('account-tracking-action-order:'.$caller.':'.(string) $request->route('order')),
            ];
        });

        // A capability URL is opened by whoever holds the token, so the token is
        // the one thing the caller actually owns here. Keying on it - not on an
        // order number anyone can guess - is what makes a stranger unable to empty
        // someone else's bucket (AGENTS.md, Failures rule 6). The IP bucket is the
        // wider net that stops the route being used to enumerate tokens at all.
        RateLimiter::for('order-tracking-link', function (Request $request): array {
            $token = (string) $request->route('token');

            return [
                // Opening this page asks the suppliers about every automated item
                // on the order, so the bucket is sized for a person looking rather
                // than for a script: one open every five seconds is already far
                // more than anyone reads. The per-job lock and the per-supplier
                // limiter are what actually cap supplier traffic; this keeps an
                // unauthenticated caller from spending that budget on one order.
                Limit::perMinute(12)->by('order-tracking-link-token:'.hash('sha256', $token)),
                Limit::perMinute(60)->by('order-tracking-link-ip:'.$request->ip()),
            ];
        });

        // Each of these actions is a supplier request on the long timeout
        // profile, so the ceiling matches what a signed-in customer gets per
        // order (three a minute) plus a wider per-IP net. The token is the one
        // thing this caller owns (AGENTS.md, Failures rule 6), and it is hashed
        // before it becomes a key so the raw capability never lands in the cache
        // store; the IP bucket stops the route being used to enumerate tokens.
        RateLimiter::for('order-tracking-link-action', function (Request $request): array {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(3)->by('order-tracking-link-action-token:'.hash('sha256', $token)),
                Limit::perMinute(10)->by('order-tracking-link-action-ip:'.$request->ip()),
            ];
        });

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

        RateLimiter::for('automation-fulfillment', function (Request $request): Limit {
            $identity = (string) ($request->header('X-ArabUT-Key') ?: $request->ip());

            return Limit::perMinute(10)
                ->by('automation-fulfillment:'.hash('sha256', $identity))
                ->response(
                    // The routing pipeline renders throttle exceptions before
                    // route middleware sees them, so the documented envelope
                    // and Retry-After headers have to come from the limiter.
                    function (Request $request, array $headers): JsonResponse {
                        return response()->json([
                            'error' => [
                                'code' => 'fulfillment_rate_limited',
                                'message' => 'Too many fulfillment placement requests.',
                            ],
                        ], 429, $headers)->header('Cache-Control', 'no-store');
                    },
                );
        });

        RateLimiter::for('paylink-webhook', fn (): Limit => Limit::perMinute(120)
            ->by('paylink-webhook'));

        RateLimiter::for('staff-payments', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('staff-payments:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Creating an order writes money records, so it gets its own budget
        // rather than sharing the read limits. Keyed on the staff member per
        // the Failures rule: a limiter keyed on an order number is no limiter.
        RateLimiter::for('staff-writes', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('staff-writes:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('staff-identity', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('staff-identity:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The lookups behind the manual-order drawer: a customer search as you
        // type, and a price suggestion each time an item changes. They read and
        // reserve nothing, so the budget is generous - but it is still keyed on
        // the staff member, because a stuck field re-asking forever is exactly
        // what a limiter is for.
        RateLimiter::for('staff-reads', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('staff-reads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

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

        RateLimiter::for('agent-turns', function (Request $request): array {
            if (! config('chat.enabled', false)) {
                return [Limit::none()];
            }

            $owner = app(ResolveChatOwner::class)->forRequest($request);
            $config = app(AgentRuntimeConfig::class);

            return [
                Limit::perMinute($config->turnRateLimitPerMinute())->by('agent-turns:'.$owner->idempotencyScope()),
                Limit::perMinute($config->turnIpRateLimitPerMinute())->by('agent-turns-ip:'.$request->ip()),
            ];
        });
    }
}
