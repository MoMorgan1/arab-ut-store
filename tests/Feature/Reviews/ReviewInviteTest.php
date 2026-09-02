<?php

use App\Admin\Actions\TransitionAdminOrder;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ReviewInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function reviewInviteActor(): User
{
    $actor = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('REVIEWINVITETOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

function reviewInviteOrder(array $attributes = []): Order
{
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    return Order::factory()->for($customer)->create([
        'status' => OrderStatus::InProgress,
        'channel' => 'store',
        'locale' => 'ar',
        'placed_at' => now(),
        ...$attributes,
    ]);
}

function completeOrder(User $actor, Order $order): Order
{
    return app(TransitionAdminOrder::class)->execute(
        actor: $actor,
        orderPublicId: (string) $order->public_id,
        targetStatus: OrderStatus::Completed,
        expectedStatus: $order->status,
    );
}

it('invites the customer to review one hour after the order is completed', function (): void {
    Carbon::setTestNow('2026-09-02 10:00:00');
    Notification::fake();

    $actor = reviewInviteActor();
    $order = reviewInviteOrder();

    completeOrder($actor, $order);

    Notification::assertSentTo(
        $order->user,
        ReviewInviteNotification::class,
        function (ReviewInviteNotification $notification): bool {
            $delay = $notification->delay;

            return $delay instanceof DateTimeInterface
                && Carbon::instance($delay)->equalTo(now()->addHour())
                && $notification->afterCommit;
        },
    );

    expect($order->fresh()->review_invited_at)->not->toBeNull();
});

it('never invites twice for the same order', function (): void {
    Notification::fake();

    $actor = reviewInviteActor();
    $order = reviewInviteOrder(['review_invited_at' => now()->subDay()]);

    completeOrder($actor, $order);

    Notification::assertNothingSent();
});

it('never invites for an imported Salla order', function (): void {
    Notification::fake();

    $actor = reviewInviteActor();
    $order = reviewInviteOrder(['channel' => 'salla_import']);

    expect(fn () => completeOrder($actor, $order))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
    expect($order->fresh()->review_invited_at)->toBeNull();
});

it('sends the invitation in the order locale with a link to the order', function (string $locale, string $path): void {
    $actor = reviewInviteActor();
    $order = reviewInviteOrder(['locale' => $locale]);

    $notification = new ReviewInviteNotification($order);
    $mail = $notification->toMail($order->user);

    expect($mail->subject)->toBe(trans(
        'mail.review_invite_subject',
        ['number' => $order->order_number],
        $locale,
    ))
        ->and($mail->viewData['orderUrl'])->toEndWith($path.$order->public_id)
        ->and($mail->viewData['number'])->toBe((string) $order->order_number)
        ->and($mail->markdown)->toBe('mail.review-invite');
})->with([
    'Arabic' => ['ar', '/my-account/orders/'],
    'English' => ['en', '/en/my-account/orders/'],
]);

it('renders the invitation mail with the order number and the review button', function (): void {
    $order = reviewInviteOrder();
    $rendered = (string) (new ReviewInviteNotification($order))
        ->toMail($order->user)
        ->render();

    expect($rendered)->toContain((string) $order->order_number)
        ->and($rendered)->toContain(trans('mail.review_invite_action', [], 'ar'))
        ->and($rendered)->toContain((string) $order->public_id);
});
