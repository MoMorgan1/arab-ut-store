<?php

declare(strict_types=1);

use App\Actions\AI\SelectServiceOptions;

function selectServiceOptions(string $text, string $serviceKey): array
{
    return app(SelectServiceOptions::class)->execute($text, $serviceKey);
}

test('coins happy path extracts platform, delivery, and quantity from Arabic text', function () {
    $options = selectServiceOptions('ابي 500 الف كوينز بلايستيشن سريع', 'coins');

    expect($options)->toBe([
        'platform' => 'playstation',
        'delivery' => 'fast',
        'quantity' => 500_000,
    ]);
});

test('coins happy path extracts platform and quantity for PC without delivery mode', function () {
    $options = selectServiceOptions('1m pc', 'coins');

    expect($options)->toBe([
        'platform' => 'pc',
        'quantity' => 1_000_000,
    ]);
});

test('coins leaves delivery unset when the customer never named a speed', function () {
    $options = selectServiceOptions('ابغى مليون كوينز بلايستيشن', 'coins');

    expect($options)->toBe([
        'platform' => 'playstation',
        'quantity' => 1_000_000,
    ]);
});

test('coins recognises various Arabic and English quantity forms and Arabic-Indic numerals', function () {
    // 500k with Arabic-Indic digits
    expect(selectServiceOptions('٥٠٠ الف كوينز سوني 5', 'coins'))->toBe([
        'platform' => 'playstation',
        'quantity' => 500_000,
    ]);

    // 100k textual. Xbox has no counterpart in the coins configurator, so only
    // the quantity survives - see the dedicated Xbox case below.
    expect(selectServiceOptions('مية الف كوينز اكسبوكس', 'coins'))->toBe([
        'quantity' => 100_000,
    ]);

    // 2 million textual with Arabic-Indic numerals
    expect(selectServiceOptions('٢ مليون كوينز بلايستيشن سريع', 'coins'))->toBe([
        'platform' => 'playstation',
        'delivery' => 'fast',
        'quantity' => 2_000_000,
    ]);

    // 5m in English
    expect(selectServiceOptions('5m coins on xbox', 'coins'))->toBe([
        'quantity' => 5_000_000,
    ]);

    // half million
    expect(selectServiceOptions('نص مليون بي سي', 'coins'))->toBe([
        'platform' => 'pc',
        'quantity' => 500_000,
    ]);
});

test('coins rejects unlisted quantities to degrade safely to the configurator slider', function () {
    expect(selectServiceOptions('300k playstation', 'coins'))->toBe([])
        ->and(selectServiceOptions('300 الف كوينز بلايستيشن', 'coins'))->toBe([])
        ->and(selectServiceOptions('10m pc', 'coins'))->toBe([]);
});

test('coins rejects partially-understood messages with missing platform or quantity', function () {
    expect(selectServiceOptions('ابي كوينز', 'coins'))->toBe([])
        ->and(selectServiceOptions('ابي 500 الف كوينز', 'coins'))->toBe([])
        ->and(selectServiceOptions('ابي كوينز بلايستيشن', 'coins'))->toBe([]);
});

test('coins rejects conflicting platforms or quantities in the same message', function () {
    expect(selectServiceOptions('ابي 500 الف كوينز بلايستيشن او بي سي', 'coins'))->toBe([])
        ->and(selectServiceOptions('500k or 1m on playstation', 'coins'))->toBe([]);
});

test('rivals happy path extracts current and target divisions in Arabic and English', function () {
    expect(selectServiceOptions('rivals from div 5 to elite', 'rivals'))->toBe([
        'currentDivision' => '5',
        'targetDivision' => 'elite',
    ]);

    expect(selectServiceOptions('من ٥ لإيليت', 'rivals'))->toBe([
        'currentDivision' => '5',
        'targetDivision' => 'elite',
    ]);

    expect(selectServiceOptions('ديفيجن 6 الى 2', 'rivals'))->toBe([
        'currentDivision' => '6',
        'targetDivision' => '2',
    ]);

    expect(selectServiceOptions('من ديف 7 لديف 1', 'rivals'))->toBe([
        'currentDivision' => '7',
        'targetDivision' => '1',
    ]);
});

test('rivals rejects non-advancing, backwards, or unpriced routes', function () {
    // Backwards route (division 1 to 5)
    expect(selectServiceOptions('من ديفيجن 1 الى ديفيجن 5', 'rivals'))->toBe([]);

    // Same division
    expect(selectServiceOptions('من 3 الى 3', 'rivals'))->toBe([]);

    // Non-existent division 8
    expect(selectServiceOptions('من 8 الى 1', 'rivals'))->toBe([]);
});

test('rivals rejects messages that only specify one division', function () {
    expect(selectServiceOptions('ابغى ديفيجن 5', 'rivals'))->toBe([])
        ->and(selectServiceOptions('ابغى تصعيد رايفلز', 'rivals'))->toBe([])
        ->and(selectServiceOptions('to elite', 'rivals'))->toBe([]);
});

test('fut champions happy path extracts rank and urgency in Arabic and English', function () {
    expect(selectServiceOptions('فوت شامبيونز رانك 2 عاجل', 'fut_champions'))->toBe([
        'rank' => 2,
        'urgent' => true,
    ]);

    expect(selectServiceOptions('رانك ٢ سريع', 'fut_champions'))->toBe([
        'rank' => 2,
        'urgent' => true,
    ]);

    expect(selectServiceOptions('fut champions rank 1 urgent', 'fut_champions'))->toBe([
        'rank' => 1,
        'urgent' => true,
    ]);

    expect(selectServiceOptions('ابغى فوت شامبيونز رانك 3', 'fut_champions'))->toBe([
        'rank' => 3,
    ]);
});

test('fut champions rejects out of range ranks', function () {
    expect(selectServiceOptions('فوت شامبيونز رانك 7', 'fut_champions'))->toBe([])
        ->and(selectServiceOptions('رانك 0', 'fut_champions'))->toBe([]);
});

test('fut champions rejects messages with missing rank', function () {
    expect(selectServiceOptions('ابغى فوت شامبيونز سريع', 'fut_champions'))->toBe([])
        ->and(selectServiceOptions('كم سعر الفوت شامبيونز', 'fut_champions'))->toBe([]);
});

test('sbc always returns empty options set', function () {
    expect(selectServiceOptions('ابغى تحديات SBC', 'sbc'))->toBe([])
        ->and(selectServiceOptions('sbc challenges', 'sbc'))->toBe([]);
});

test('messages where numbers are order numbers or prices return no options', function () {
    expect(selectServiceOptions('وين طلبي رقم 5000', 'coins'))->toBe([])
        ->and(selectServiceOptions('وين طلبي رقم 5000', 'rivals'))->toBe([])
        ->and(selectServiceOptions('check order #5000', 'coins'))->toBe([])
        ->and(selectServiceOptions('شيك طلبي رقم 100000', 'coins'))->toBe([]);
});

test('an Xbox coins question keeps the quantity but names no platform', function () {
    // The storefront's coins configurator offers PlayStation and PC only, so a
    // card claiming Xbox would contradict the page it opens.
    $options = app(SelectServiceOptions::class)
        ->execute('ابي 500 الف كوينز اكس بوكس سريع', 'coins');

    expect($options)->toBe(['quantity' => 500_000]);
});
