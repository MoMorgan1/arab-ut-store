<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('an Arabic customer receives the password reset email in Arabic with configured expiry', function () {
    Notification::fake();
    config(['auth.passwords.users.expire' => 45]);

    $user = User::factory()->create(['preferred_locale' => 'ar']);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('إعادة تعيين كلمة المرور لحسابك في عرب ألتميت')
            ->and($mail->introLines)->toContain('تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بحسابك في عرب ألتميت.')
            ->and($mail->actionText)->toBe('إعادة تعيين كلمة المرور')
            ->and($mail->outroLines)->toContain('تنتهي صلاحية رابط إعادة التعيين هذا خلال 45 دقيقة.')
            ->and($mail->outroLines)->toContain('إذا لم تطلب إعادة تعيين كلمة المرور، فلا يلزمك اتخاذ أي إجراء.')
            ->and($mail->salutation)->toBe("تحياتنا،\nفريق عرب ألتميت")
            ->and($mail->actionUrl)->toStartWith(url('/reset-password/'))
            ->and($mail->actionUrl)->not->toContain('/en/reset-password/');

        return true;
    });
});

test('an English customer receives the password reset email in English with localized reset link', function () {
    Notification::fake();
    config(['auth.passwords.users.expire' => 30]);

    $user = User::factory()->create(['preferred_locale' => 'en']);

    $this->from('/en/forgot-password')
        ->post('/en/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Reset your Arab UT account password')
            ->and($mail->introLines)->toContain('You are receiving this email because we received a password reset request for your account.')
            ->and($mail->actionText)->toBe('Reset password')
            ->and($mail->outroLines)->toContain('This password reset link will expire in 30 minutes.')
            ->and($mail->outroLines)->toContain('If you did not request a password reset, no further action is required.')
            ->and($mail->salutation)->toBe("Regards,\nArab UT team")
            ->and($mail->actionUrl)->toStartWith(url('/en/reset-password/'));

        return true;
    });
});

test('a customer with no preferred locale defaults to Arabic reset email', function () {
    Notification::fake();

    $user = User::factory()->create();
    $user->preferred_locale = null;

    $user->sendPasswordResetNotification('test-token');

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('إعادة تعيين كلمة المرور لحسابك في عرب ألتميت')
            ->and($mail->introLines)->toContain('تلقينا طلبًا لإعادة تعيين كلمة المرور الخاصة بحسابك في عرب ألتميت.')
            ->and($mail->actionText)->toBe('إعادة تعيين كلمة المرور')
            ->and($mail->actionUrl)->toStartWith(url('/reset-password/'))
            ->and($mail->actionUrl)->not->toContain('/en/reset-password/');

        return true;
    });
});
