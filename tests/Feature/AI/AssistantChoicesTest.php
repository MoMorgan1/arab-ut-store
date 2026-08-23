<?php

declare(strict_types=1);

use App\Actions\AI\BuildAssistantChoices;

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
