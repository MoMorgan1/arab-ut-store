<?php

namespace App\Providers;

use App\Actions\Cart\ResolveCartOwner;
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
        //
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

        RateLimiter::for('automation-catalog', function (Request $request): Limit {
            $identity = (string) ($request->header('X-ArabUT-Key') ?: $request->ip());

            return Limit::perMinute(10)
                ->by('automation-catalog:'.hash('sha256', $identity));
        });
    }
}
