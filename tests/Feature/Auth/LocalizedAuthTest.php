<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('auth screens expose localized copy direction and route contracts', function (
    string $path,
    string $component,
    string $authPage,
    string $locale,
    string $direction,
    string $title,
) {
    $localized = $locale === 'en';
    $prefix = $localized ? '/en' : '';

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where('authPage', $authPage)
        ->where('locale', $locale)
        ->where('direction', $direction)
        ->where("authUi.{$authPage}.title", $title)
        ->where('authRoutes.homeUrl', $localized ? '/en' : '/')
        ->where('authRoutes.loginUrl', "{$prefix}/login")
        ->where('authRoutes.loginStoreUrl', "{$prefix}/login")
        ->where('authRoutes.registerUrl', "{$prefix}/register")
        ->where('authRoutes.registerStoreUrl', "{$prefix}/register")
        ->where('authRoutes.forgotPasswordUrl', "{$prefix}/forgot-password")
        ->where('authRoutes.forgotPasswordStoreUrl', "{$prefix}/forgot-password")
        ->where('authRoutes.resetPasswordStoreUrl', "{$prefix}/reset-password"));
})->with([
    'Arabic login' => ['/login', 'auth/login', 'login', 'ar', 'rtl', 'تسجيل الدخول إلى حسابك'],
    'English login' => ['/en/login', 'auth/login', 'login', 'en', 'ltr', 'Log in to your account'],
    'Arabic registration' => ['/register', 'auth/register', 'register', 'ar', 'rtl', 'إنشاء حساب'],
    'English registration' => ['/en/register', 'auth/register', 'register', 'en', 'ltr', 'Create an account'],
    'Arabic forgot password' => ['/forgot-password', 'auth/forgot-password', 'forgot_password', 'ar', 'rtl', 'نسيت كلمة المرور؟'],
    'English forgot password' => ['/en/forgot-password', 'auth/forgot-password', 'forgot_password', 'en', 'ltr', 'Forgot your password?'],
    'Arabic reset password' => ['/reset-password/test-token?email=player@example.test', 'auth/reset-password', 'reset_password', 'ar', 'rtl', 'تعيين كلمة مرور جديدة'],
    'English reset password' => ['/en/reset-password/test-token?email=player@example.test', 'auth/reset-password', 'reset_password', 'en', 'ltr', 'Set a new password'],
]);

test('English registration records the originating locale', function () {
    $this->post('/en/register', [
        'first_name' => 'English',
        'last_name' => 'Player',
        'email' => 'english-player@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect('/dashboard');

    expect(User::where('email', 'english-player@example.test')->value('preferred_locale'))->toBe('en');
});

test('password reset email and completion preserve the originating English locale', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->from('/en/forgot-password')
        ->post('/en/forgot-password', ['email' => $user->email])
        ->assertRedirect('/en/forgot-password');

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        expect($mail->actionUrl)->toContain('/en/reset-password/')
            ->and($mail->actionUrl)->toContain('email='.urlencode($user->email));

        return true;
    });

    $token = Password::createToken($user);
    $this->post('/en/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect('/en/login');
});
