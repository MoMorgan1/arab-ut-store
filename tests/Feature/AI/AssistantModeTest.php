<?php

use App\Actions\AI\ResolveAssistantMode;
use App\Enums\AI\AssistantMode;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Str;

test('runtime defaults fail closed and an eligible owner receives no demo reply', function () {
    config()->set('chat.enabled', true);
    config()->set('chat.demo_assistant', true);
    config()->set('ai-assistant.enabled', false);
    config()->set('ai-assistant.rollout', 'disabled');
    config()->set('ai-assistant.provider', '');

    expect(app(ResolveAssistantMode::class)->for(ChatOwner::user(7)))
        ->toBe(AssistantMode::Demo);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    config()->set('ai-assistant.provider', 'fake');

    $clientMessageId = (string) Str::uuid();

    $response = $this->actingAs($user)->postJson(
        route('chat.messages.store', ['conversation' => $conversation->public_id]),
        ['content' => 'اختبار وضع المساعد', 'client_message_id' => $clientMessageId],
    );

    $response->assertCreated()->assertJsonPath('data.demoReply', null);
    $customerMessage = $conversation->messages()->where('client_message_id', $clientMessageId)->sole();

    expect($conversation->messages()->count())->toBe(1)
        ->and($customerMessage->sender_type->value)->toBe('customer')
        ->and($conversation->messages()
            ->where('reply_to_message_id', $customerMessage->id)
            ->where('sender_type', 'assistant')
            ->exists())->toBeFalse();
    expect(app(ResolveAssistantMode::class)->for(ChatOwner::user($user->id)))
        ->toBe(AssistantMode::Agent);
});

test('public is implemented but an invalid rollout never selects an owner', function () {
    config()->set('chat.demo_assistant', false);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'unexpected');
    config()->set('ai-assistant.provider', 'fake');

    expect(app(ResolveAssistantMode::class)->for(ChatOwner::user(9)))
        ->toBe(AssistantMode::None);

    config()->set('ai-assistant.rollout', 'public');

    expect(app(ResolveAssistantMode::class)->for(ChatOwner::guest(str_repeat('a', 64))))
        ->toBe(AssistantMode::Agent);
});

test('a selected tester with missing provider remains agent mode for the later fail-closed route', function () {
    config()->set('chat.demo_assistant', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.test_user_ids', [9]);
    config()->set('ai-assistant.provider', '');

    expect(app(ResolveAssistantMode::class)->for(ChatOwner::user(9)))
        ->toBe(AssistantMode::Agent);
});
