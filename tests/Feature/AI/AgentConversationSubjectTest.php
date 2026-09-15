<?php

use App\Actions\AI\StreamAgentTurn;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\AI\AppStreamEventType;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentTurn;
use App\Models\ChatMessage;
use App\Support\AI\SubjectText;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\Chat\ChatOwner;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;
use Tests\Support\AI\SequencedAgentModel;

beforeEach(function (): void {
    config()->set('ai-assistant.provider', 'fake');
});

test('the first reply is followed by a subject event and the conversation is titled', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    $model = new SequencedAgentModel([
        ScriptedAgentModel::completed(['أكيد، ', 'سعر المليون كوينز اليوم 25 ريال.']),
        ScriptedAgentModel::completed(['«سعر الكوينز اليوم».']),
    ]);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver($model));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    $conversation = $turn->conversation->fresh();

    expect($conversation->subject)->toBe('سعر الكوينز اليوم')
        ->and($turn->fresh()->status)->toBe(AgentTurnStatus::Completed)
        ->and(array_map(fn ($event) => $event->type, $events))->toBe([
            AppStreamEventType::TurnCreated,
            AppStreamEventType::Delta,
            AppStreamEventType::Delta,
            AppStreamEventType::Completed,
            AppStreamEventType::Subject,
        ])
        ->and($events[4]->conversationPublicId)->toBe((string) $conversation->public_id)
        ->and($events[4]->subject)->toBe('سعر الكوينز اليوم');

    $subjectRequest = $model->requests()[1];
    expect($subjectRequest)->toBeInstanceOf(AgentModelRequest::class)
        ->and($subjectRequest->instructions)->toStartWith('# Conversation title')
        ->and($subjectRequest->messages[0]['role'])->toBe('user')
        ->and($subjectRequest->messages[1]['role'])->toBe('assistant')
        ->and($subjectRequest->messages[1]['content'])->toBe('أكيد، سعر المليون كوينز اليوم 25 ريال.')
        ->and($subjectRequest->maxOutputTokens)->toBe(60);
});

test('a later reply never renames the conversation', function () {
    $turn = AgentTurn::factory()->create();
    $conversation = $turn->conversation;
    ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => ChatSenderType::Assistant,
        'content' => 'رد سابق',
    ]);
    $conversation->forceFill(['subject' => 'عنوان قائم'])->save();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $model = new SequencedAgentModel([
        ScriptedAgentModel::completed(['رد ثانٍ.']),
        ScriptedAgentModel::completed(['عنوان جديد']),
    ]);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver($model));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    expect($conversation->fresh()->subject)->toBe('عنوان قائم')
        ->and($model->invocationCount())->toBe(1)
        ->and(end($events)->type)->toBe(AppStreamEventType::Completed);
});

test('a failed title call leaves the turn complete and the subject empty', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    $model = new SequencedAgentModel([
        ScriptedAgentModel::completed(['الرد الأول.']),
        ScriptedAgentModel::failures([['code' => AgentErrorCode::ProviderServerError]]),
    ]);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver($model));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    expect($turn->fresh()->status)->toBe(AgentTurnStatus::Completed)
        ->and($turn->conversation->fresh()->subject)->toBeNull()
        ->and(end($events)->type)->toBe(AppStreamEventType::Completed);
});

test('the subject call is skipped when the feature is off', function () {
    config()->set('ai-assistant.subject.enabled', false);
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    $model = ScriptedAgentModel::completed(['الرد الأول.']);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver($model));

    iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    expect($model->invocationCount())->toBe(1)
        ->and($turn->conversation->fresh()->subject)->toBeNull();
});

test('subject text keeps one clean line and rejects a ramble', function (string $raw, ?string $expected) {
    expect(SubjectText::clean($raw))->toBe($expected);
})->with([
    ['Coins price today.', 'Coins price today'],
    ["\"Rivals boost to Division 3\"\n\nMore words", 'Rivals boost to Division 3'],
    ['العنوان: تفاصيل خدمة الفوت', 'تفاصيل خدمة الفوت'],
    ['  سؤال   عن   الضمان؟ ', 'سؤال عن الضمان'],
    ['', null],
    ['«»', null],
    [str_repeat('ب', 81), null],
]);
