<?php

use App\Actions\Support\SendStaffReply;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

/**
 * There is no `last_customer_message_at` column: how long the customer has been
 * away is derived from their newest message, because `last_message_at` moves on
 * staff replies too. The helper writes the message these tests used to fake
 * with a column that never existed.
 */
function customerMessageAgo(ChatConversation $conversation, int $minutes): ChatMessage
{
    $at = now()->subMinutes($minutes);

    $message = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'Customer message',
    ]);

    // created_at is set by Eloquent timestamps, so it has to be pushed back
    // after the insert rather than passed to create().
    $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

    return $message->refresh();
}

it('sends an email notification when customer is away (>= 5 minutes inactive) and not throttled', function (): void {
    Notification::fake();

    $customer = User::factory()->create([
        'email' => 'customer@example.com',
        'first_name' => 'Ahmed',
        'last_name' => 'Khaled',
    ]);
    $staff = User::factory()->create([
        'first_name' => 'Mohamed',
        'last_name' => 'Said',
    ]);

    $conversation = ChatConversation::factory()->forUser($customer)->create();
    customerMessageAgo($conversation, 10);

    $ticket = SupportTicket::factory()->for($conversation, 'conversation')->for($customer, 'user')->create([
        'last_notified_at' => null,
    ]);

    $action = app(SendStaffReply::class);
    $action->execute($ticket, $staff, 'Staff response text');

    Notification::assertSentTo(
        $customer,
        SupportReplyNotification::class,
        function (SupportReplyNotification $notification) use ($customer) {
            expect($notification)->not->toBeInstanceOf(ShouldQueue::class);

            $mail = $notification->toMail($customer);
            expect($mail->subject)->toContain('تذكرة')
                ->and($mail->greeting)->toContain('Ahmed')
                ->and($mail->introLines[0])->toContain('Mohamed')
                // Never leaks message content or transcript in the email
                ->and(json_encode($mail->introLines))->not->toContain('Staff response text');

            return true;
        }
    );

    expect($ticket->fresh()->last_notified_at)->not->toBeNull();
});

it('does NOT send email notification when customer was recently active (< 5 minutes ago)', function (): void {
    Notification::fake();

    $customer = User::factory()->create();
    $staff = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create();
    customerMessageAgo($conversation, 2);

    $ticket = SupportTicket::factory()->for($conversation, 'conversation')->for($customer, 'user')->create([
        'last_notified_at' => null,
    ]);

    $action = app(SendStaffReply::class);
    $action->execute($ticket, $staff, 'Instant staff response');

    Notification::assertNothingSent();
    expect($ticket->fresh()->last_notified_at)->toBeNull();
});

it('does NOT send duplicate email notification when throttled (< 1 hour since last notification)', function (): void {
    Notification::fake();

    $customer = User::factory()->create();
    $staff = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create();
    customerMessageAgo($conversation, 30);

    $ticket = SupportTicket::factory()->for($conversation, 'conversation')->for($customer, 'user')->create([
        'last_notified_at' => now()->subMinutes(20),
    ]);

    $action = app(SendStaffReply::class);
    $action->execute($ticket, $staff, 'Second staff response in quick succession');

    Notification::assertNothingSent();
});
