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

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');

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

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');

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
        $turn->prompt_version = 'support-v9';
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
        app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');
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
        app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');
        $this->fail('Expected sensitive content rejection.');
    } catch (SensitiveAgentContentException $exception) {
        expect($exception->getMessage())->not->toContain($sensitiveContent);
    }
})->with([
    'password label' => 'My PASSWORD is "hunter2secret"',
    'passcode label' => 'passcode: synthetic123456',
    'backup code label' => 'backup code 12345678',
    'recovery code label' => 'recovery code ABCDEF123456ABCDEF',
    'API key label' => 'API KEY: sk_abcdefghijklmnopqrstuvwxyz',
    'secret label' => 'secret: synthetic123456',
    'token label' => 'token ghp_abcdefghijklmnopqrst',
    'CVV label' => 'CVV 123',
    'CVC label' => 'CVC 123',
    'Arabic password label' => 'كلمة المرور: تجريبية123456',
    'Arabic password spelling' => 'كلمه المرور: تجريبية123456',
    'Arabic backup code' => 'رمز احتياطي 12345678',
    'Arabic backup codes' => 'رموز احتياطية 12345678',
    'Arabic API key' => 'مفتاح API: sk_abcdefghijklmnopqrstuvwxyz',
    'Arabic verification code' => 'رمز التحقق: تجريبية123456',
    'Bearer token' => 'Bearer abcdefghijklmnop',
    'OpenAI-shaped token' => 'sk-abcdefghijklmnop',
    '13 digit payment card boundary' => 'card 4222 2222 2222 2',
    'payment card candidate' => 'card 4242 4242 4242 4242',
    '19 digit payment card boundary' => 'card 4000 0000 0000 0000 006',
    'Arabic-Indic payment card' => 'بطاقتي ٤٤٤٤ ٣٣٣٣ ٢٢٢٢ ١١١١',
    'Eastern Arabic-Indic payment card' => 'بطاقة ۴۴۴۴-۳۳۳۳-۲۲۲۲-۱۱۱۱',
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

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');

    expect(array_column($request->messages, 'content'))->toBe([$ordinaryContent]);
})->with([
    'short sk fragment' => 'Order reference sk-abcdefghijklmno',
    'two eight digit groups' => 'References 12345678 and 23456789',
    'repeated eight digit group' => 'References 12345678 12345678 12345678',
    'invalid Luhn candidate' => 'card 4242 4242 4242 4241',
    '20 digit Luhn-like sequence' => 'card 42424242424242424242',
    'Arabic order number' => 'طلبي رقم ١٢٣٤٥ ما وصل',
    'short Arabic digits' => 'رقمي ٠٥٥٥٥',
    'bare Luhn-valid PAN with no card terminology' => '4242 4242 4242 4242',
    'three distinct EA backup groups' => '12345678 23456789 34567890',
    'bare Arabic-Indic PAN without card terminology' => '٤٤٤٤ ٣٣٣٣ ٢٢٢٢ ١١١١',
    'Arabic-Indic three backup groups' => '١٢٣٤٥٦٧٨ ٢٣٤٥٦٧٨٩ ٣٤٥٦٧٨٩٠',
    'Arabic label without secret value' => 'نسيت كلمة المرور ماذا أفعل',
    'English label without secret value' => 'I forgot my password',
    'EA order reference' => 'رقم الطلب EA-2026-0821 ما وصل',
    'card question without numbers' => 'هل الدفع بالبطاقة آمن؟',
]);

test('prior context tripping guard is excluded without poisoning current claim', function () {
    config()->set('ai-assistant.max_context_messages', 5);
    $conversation = ChatConversation::factory()->create(['locale' => 'en']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);

    $priorCustomer = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'prior-customer']);
    $priorTurn = AgentTurn::factory()->completed()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $priorCustomer->id,
        'last_customer_message_id' => $priorCustomer->id,
    ]);
    $priorTurn->assistantMessage()->update([
        'content' => 'Never share your token: synthetic123456 with anyone.',
    ]);

    $current = ChatMessage::factory()->count(2)->customer()->agentEligible()
        ->for($conversation, 'conversation')->sequence(
            ['content' => 'current-one'],
            ['content' => 'current-two'],
        )->create();
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $current->first()->id,
        'last_customer_message_id' => $current->last()->id,
    ]);

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');

    expect($request->messages)->toBe([
        ['role' => 'user', 'content' => 'prior-customer'],
        ['role' => 'user', 'content' => 'current-one'],
        ['role' => 'user', 'content' => 'current-two'],
    ]);
});
