<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User && $user->hasEnabledTwoFactorAuthentication()) {
            $this->withSession(['auth.two_factor_confirmed_at' => now()->timestamp]);
        }

        return parent::actingAs($user, $guard);
    }

    public function withoutTwoFactorSession(): static
    {
        $this->startSession();
        $this->app['session']->forget('auth.two_factor_confirmed_at');

        return $this;
    }
}
