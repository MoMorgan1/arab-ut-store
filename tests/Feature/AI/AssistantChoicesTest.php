<?php

declare(strict_types=1);

use App\Actions\AI\BuildAssistantChoices;
use App\Actions\AI\SelectServiceOptions;

function assistantChoices(string $text, string $locale = 'ar'): ?array
{
    return app(BuildAssistantChoices::class)->execute($text, $locale);
}

function choiceLabels(?array $choices): array
{
    return $choices === null ? [] : array_column($choices['items'], 'label');
}

test('a broad pricing question asks which service instead of listing every price', function () {
    // The whole point: "الأسعار" used to earn a wall of every platform and
    // speed. Now it earns one question.
    $choices = assistantChoices('الأسعار');

    expect($choices)->not->toBeNull()
        ->and($choices['version'])->toBe('choices.v1')
        ->and($choices['items'])->toHaveCount(4);
});

test('coins are asked for one missing option at a time', function () {
    expect(choiceLabels(assistantChoices('ابي كوينز')))->toHaveCount(2)
        ->and(choiceLabels(assistantChoices('ابي كوينز بلايستيشن')))->toHaveCount(5)
        ->and(choiceLabels(assistantChoices('مليون كوينز بلايستيشن')))->toHaveCount(2);
});

test('a fully specified coins request has nothing left to ask', function () {
    expect(assistantChoices('مليون كوينز بلايستيشن سريع'))->toBeNull();
});

test('PC coins skip the delivery question because PC has one speed', function () {
    expect(assistantChoices('نص مليون كوينز بي سي'))->toBeNull();
});

test('champions ask for the rank and then the urgency', function () {
    expect(choiceLabels(assistantChoices('ابي فوت شامبيونز')))->toHaveCount(6)
        ->and(choiceLabels(assistantChoices('رانك 2 فوت شامبيونز')))->toHaveCount(2)
        ->and(assistantChoices('رانك 2 فوت شامبيونز مستعجل'))->toBeNull();
});

test('an order question is never turned into a sales question', function () {
    expect(assistantChoices('وين طلبي رقم 5000'))->toBeNull();
});

test('every chip message is something the detector can read back', function () {
    // A chip is only useful if sending it actually advances the funnel, so each
    // message has to be understood by the same parser that produced the ask.
    $platform = assistantChoices('ابي كوينز');
    $next = assistantChoices('ابي كوينز '.$platform['items'][0]['message']);

    expect(choiceLabels($next))->toHaveCount(5);
});

test('chips are offered in English too', function () {
    $choices = assistantChoices('I want coins', 'en');

    expect($choices)->not->toBeNull()
        ->and($choices['prompt'])->toBe('Which platform?');
});

test('a bare Rivals question asks which division the customer is in now', function () {
    // Rivals is priced by route, and the store's own answer used to be "the
    // price is on the product page" forever, because a message like
    // "كم سعر ديفيجن 1؟" names one end and the assistant could not tell which.
    $choices = assistantChoices('كم سعر ديفيجن 1؟');

    expect($choices)->not->toBeNull()
        ->and($choices['items'])->toHaveCount(7)
        ->and(array_column($choices['items'], 'id'))->toBe([
            'rivals-current:7',
            'rivals-current:6',
            'rivals-current:5',
            'rivals-current:4',
            'rivals-current:3',
            'rivals-current:2',
            'rivals-current:1',
        ]);
});

test('naming the current division asks for the target next', function () {
    $choices = assistantChoices('ابي رايفلز انا في ديفيجن 5');

    expect($choices)->not->toBeNull()
        ->and(array_column($choices['items'], 'id'))
        ->each->toStartWith('rivals-target:');
});

test('a target chip carries the whole route, because a turn sees one message', function () {
    $choices = assistantChoices('ابي رايفلز انا في ديفيجن 5');
    $messages = array_column($choices['items'] ?? [], 'message');

    // Every chip must round-trip: sending it back has to resolve a full route.
    foreach ($messages as $message) {
        expect(app(SelectServiceOptions::class)->execute($message, 'rivals'))
            ->toHaveKeys(['currentDivision', 'targetDivision']);
    }
});

test('a complete Rivals route has nothing left to ask', function () {
    expect(assistantChoices('ابي رايفلز من ٥ لإيليت'))->toBeNull();
});

test('the English Rivals flow asks and round-trips the same way', function () {
    $choices = assistantChoices('I want Rivals, I am in division 6', 'en');

    expect($choices)->not->toBeNull();

    foreach (array_column($choices['items'], 'message') as $message) {
        expect(app(SelectServiceOptions::class)->execute($message, 'rivals'))
            ->toHaveKeys(['currentDivision', 'targetDivision']);
    }
});

/**
 * Walks the funnel by tapping the first chip each time, exactly as a customer
 * would, and returns every message sent along the way.
 *
 * @return list<string>
 */
function walkChoices(string $opening, string $locale = 'ar', int $limit = 6): array
{
    $sent = [$opening];
    $message = $opening;

    for ($step = 0; $step < $limit; $step++) {
        $choices = assistantChoices($message, $locale);

        if ($choices === null) {
            return $sent;
        }

        $message = $choices['items'][0]['message'];
        $sent[] = $message;
    }

    throw new RuntimeException('The funnel never resolved: '.implode(' -> ', $sent));
}

test('tapping through coins reaches a complete order without dead-ending', function () {
    // The bug this guards: a platform chip that said only "بلايستيشن" arrived
    // on the next turn with no service attached, so the assistant lost the
    // thread and pointed the customer at the product page instead of asking
    // the next question.
    $walk = walkChoices('ابي كوينز');
    $final = end($walk);

    expect(app(SelectServiceOptions::class)->execute($final, 'coins'))
        ->toHaveKeys(['platform', 'delivery', 'quantity']);
});

test('every coins chip still names the service it belongs to', function () {
    // A chip that stops mentioning coins arrives on the next turn as an
    // orphan: the topic selector no longer resolves a service and the funnel
    // restarts from "which service?".
    foreach (walkChoices('ابي كوينز') as $message) {
        $next = assistantChoices($message);

        if ($next === null) {
            continue;
        }

        expect($next['items'][0]['id'])->toStartWith('coins-', $message);
    }
});

test('the English coins funnel resolves the same way', function () {
    $walk = walkChoices('I want coins', 'en');

    expect(app(SelectServiceOptions::class)->execute(end($walk), 'coins'))
        ->toHaveKeys(['platform', 'delivery', 'quantity']);
});

test('PC resolves without ever being asked for a speed', function () {
    $choices = assistantChoices('ابي كوينز بي سي');
    $message = $choices['items'][0]['message'];

    expect(assistantChoices($message))->toBeNull()
        ->and(app(SelectServiceOptions::class)->execute($message, 'coins'))
        ->toHaveKeys(['platform', 'quantity']);
});

test('tapping through FUT Champions reaches a complete order', function () {
    $walk = walkChoices('ابي فوت شامبيونز');

    expect(app(SelectServiceOptions::class)->execute(end($walk), 'fut_champions'))
        ->toHaveKeys(['rank', 'urgent']);
});

test('tapping through Rivals reaches a complete route', function () {
    $walk = walkChoices('ابي رايفلز');

    expect(app(SelectServiceOptions::class)->execute(end($walk), 'rivals'))
        ->toHaveKeys(['currentDivision', 'targetDivision']);
});
