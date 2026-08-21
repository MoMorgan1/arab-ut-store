<?php

use App\Actions\AI\BuildAgentModelRequest;
use App\Exceptions\AI\InvalidAgentRequestException;
use App\Exceptions\AI\SensitiveAgentContentException;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\ValueObjects\Chat\ChatOwner;

test('prompt includes every claimed customer and fills only remaining configured slots', function () {
    config()->set('ai-assistant.max_context_messages', 4);
    $conversation = ChatConversation::factory()->create(['locale' => 'en']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $priorCustomer = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'prior-customer']);
    $priorTurn = AgentTurn::factory()->completed()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $priorCustomer->id,
        'last_customer_message_id' => $priorCustomer->id,
    ]);
    $priorTurn->assistantMessage()->update(['content' => 'prior-assistant']);
    $claimed = ChatMessage::factory()->count(3)->customer()->agentEligible()
        ->for($conversation, 'conversation')->sequence(
            ['content' => 'current-one'],
            ['content' => 'current-two'],
            ['content' => 'current-three'],
        )->create();
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $claimed->first()->id,
        'last_customer_message_id' => $claimed->last()->id,
    ]);

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner);

    expect($request->messages)->toBe([
        ['role' => 'assistant', 'content' => 'prior-assistant'],
        ['role' => 'user', 'content' => 'current-one'],
        ['role' => 'user', 'content' => 'current-two'],
        ['role' => 'user', 'content' => 'current-three'],
    ])->and($request->instructions)->toContain('Conversation locale: en. Authenticated customer: no.');
});

test('request safety identifier is the exact lowercase owner-scope HMAC', function () {
    config()->set('app.key', 'synthetic-app-key');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create();
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner);

    expect($request->safetyIdentifier)->toBe(hash_hmac(
        'sha256',
        'guest:'.$conversation->guest_key,
        'synthetic-app-key',
    ))->toMatch('/\A[0-9a-f]{64}\z/D');
});

test('invalid prompt versions ranges roles and types are rejected without content', function (string $invalidCase): void {
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $customer = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'ordinary-current-message']);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->make([
        'first_customer_message_id' => $customer->id,
        'last_customer_message_id' => $customer->id,
    ]);
    if ($invalidCase === 'prompt version') {
        $turn->prompt_version = 'support-v2';
    } elseif ($invalidCase === 'reversed range') {
        $later = ChatMessage::factory()->customer()->agentEligible()->for($conversation, 'conversation')->create();
        $turn->first_customer_message_id = $later->id;
        $turn->last_customer_message_id = $later->id - 1;
    } elseif ($invalidCase === 'assistant role') {
        $assistant = ChatMessage::factory()->assistant()->for($conversation, 'conversation')->create();
        $turn->first_customer_message_id = $assistant->id;
        $turn->last_customer_message_id = $assistant->id;
    } else {
        $systemType = ChatMessage::factory()->customer()->agentEligible()->for($conversation, 'conversation')->create([
            'message_type' => 'system',
        ]);
        $turn->first_customer_message_id = $systemType->id;
        $turn->last_customer_message_id = $systemType->id;
    }
    $turn->save();

    try {
        app(BuildAgentModelRequest::class)->execute($turn, $owner);
        $this->fail('Expected invalid request rejection.');
    } catch (InvalidAgentRequestException $exception) {
        expect($exception->getMessage())->not->toContain('ordinary-current-message');
    }
})->with([
    'prompt version' => 'prompt version',
    'reversed range' => 'reversed range',
    'assistant role' => 'assistant role',
    'system message type' => 'system message type',
]);

test('sensitive prompt patterns fail before a model request is built', function (string $sensitiveContent): void {
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => $sensitiveContent]);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    try {
        app(BuildAgentModelRequest::class)->execute($turn, $owner);
        $this->fail('Expected sensitive content rejection.');
    } catch (SensitiveAgentContentException $exception) {
        expect($exception->getMessage())->not->toContain($sensitiveContent);
    }
})->with([
    'password label' => 'My PASSWORD is synthetic',
    'passcode label' => 'passcode: synthetic',
    'backup code label' => 'backup code synthetic',
    'recovery code label' => 'recovery code synthetic',
    'API key label' => 'API KEY synthetic',
    'secret label' => 'secret synthetic',
    'token label' => 'token synthetic',
    'CVV label' => 'CVV 123',
    'CVC label' => 'CVC 123',
    'Arabic password label' => 'كلمة المرور تجريبية',
    'Arabic password spelling' => 'كلمه المرور تجريبية',
    'Arabic backup code' => 'رمز احتياطي تجريبي',
    'Arabic backup codes' => 'رموز احتياطية تجريبية',
    'Arabic API key' => 'مفتاح API تجريبي',
    'Arabic verification code' => 'رمز التحقق تجريبي',
    'Bearer token' => 'Bearer abcdefghijklmnop',
    'OpenAI-shaped token' => 'sk-abcdefghijklmnop',
    'three distinct EA backup groups' => '12345678 23456789 34567890',
    '13 digit payment card boundary' => '4222 2222 2222 2',
    'payment card candidate' => '4242 4242 4242 4242',
    '19 digit payment card boundary' => '4000 0000 0000 0000 006',
    'Arabic-Indic payment card' => '٤٤٤٤ ٣٣٣٣ ٢٢٢٢ ١١١١',
    'Eastern Arabic-Indic payment card' => '۴۴۴۴-۳۳۳۳-۲۲۲۲-۱۱۱۱',
    'Arabic-Indic three backup groups' => '١٢٣٤٥٦٧٨ ٢٣٤٥٦٧٨٩ ٣٤٥٦٧٨٩٠',
]);

test('near misses do not block ordinary support content', function (string $ordinaryContent): void {
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => $ordinaryContent]);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner);

    expect(array_column($request->messages, 'content'))->toBe([$ordinaryContent]);
})->with([
    'short sk fragment' => 'Order reference sk-abcdefghijklmno',
    'two eight digit groups' => 'References 12345678 and 23456789',
    'repeated eight digit group' => 'References 12345678 12345678 12345678',
    'invalid Luhn candidate' => '4242 4242 4242 4241',
    '20 digit Luhn-like sequence' => '42424242424242424242',
    'Arabic order number' => 'طلبي رقم ١٢٣٤٥ ما وصل',
    'short Arabic digits' => 'رقمي ٠٥٥٥٥',
]);
