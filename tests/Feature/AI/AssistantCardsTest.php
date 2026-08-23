<?php

declare(strict_types=1);

use App\Actions\AI\BuildAssistantCards;

function assistantCards(string $text, string $locale = 'ar'): array
{
    return app(BuildAssistantCards::class)->execute($text, $locale);
}

test('a plain coins question offers a plain coins card without preselected options', function () {
    $cards = assistantCards('ابغى كوينز');

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['id'])->toBe('coins')
        ->and($cards[0]['url'])->toBe('/#coins')
        ->and($cards[0]['title'])->toBe('شحن كوينز FC')
        ->and($cards[0]['cta'])->toBe('اطلب الآن')
        ->and($cards[0]['options'])->toBe([]);
});

test('a coins question with options deep-links and carries localized options list', function () {
    $cards = assistantCards('ابي 500 الف كوينز بلايستيشن سريع');

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['id'])->toBe('coins')
        ->and($cards[0]['url'])->toBe('/?platform=playstation&delivery=fast&quantity=500000#coins')
        ->and($cards[0]['title'])->toBe('شحن كوينز FC')
        ->and($cards[0]['cta'])->toBe('اطلب الآن')
        ->and($cards[0]['options'])->toBe([
            ['label' => 'المنصة', 'value' => 'بلايستيشن'],
            ['label' => 'التسليم', 'value' => 'سريع'],
            ['label' => 'الكمية', 'value' => '500,000 كوينز'],
        ]);
});

test('an English conversation links into the English storefront with query parameters and hash', function () {
    $cards = assistantCards('I want 500k coins on playstation fast', 'en');

    expect($cards[0]['url'])->toBe('/en?platform=playstation&delivery=fast&quantity=500000#coins')
        ->and($cards[0]['title'])->toBe('FC Coins')
        ->and($cards[0]['options'])->toBe([
            ['label' => 'Platform', 'value' => 'PlayStation'],
            ['label' => 'Delivery', 'value' => 'Fast'],
            ['label' => 'Quantity', 'value' => '500,000 Coins'],
        ]);
});

test('a rivals question with route preselects current and target divisions', function () {
    $cards = assistantCards('ابي رايفلز من ٥ لإيليت');

    expect($cards[0]['id'])->toBe('rivals')
        ->and($cards[0]['url'])->toBe('/rivals?currentDivision=5&targetDivision=elite')
        ->and($cards[0]['options'])->toBe([
            ['label' => 'الديفجن الحالي', 'value' => 'ديفجن 5'],
            ['label' => 'الديفجن المطلوب', 'value' => 'إيليت'],
        ]);
});

test('a rivals question in English preselects divisions with English labels', function () {
    $cards = assistantCards('rivals from div 5 to elite', 'en');

    expect($cards[0]['id'])->toBe('rivals')
        ->and($cards[0]['url'])->toBe('/en/rivals?currentDivision=5&targetDivision=elite')
        ->and($cards[0]['options'])->toBe([
            ['label' => 'Current division', 'value' => 'Division 5'],
            ['label' => 'Target division', 'value' => 'Elite'],
        ]);
});

test('a fut champions question with rank and urgency preselects rank and urgent mode', function () {
    $cards = assistantCards('ابغى فوت شامبيونز رانك ٢ سريع');

    expect($cards[0]['id'])->toBe('fut_champions')
        ->and($cards[0]['url'])->toBe('/fut-champions?rank=2&urgent=1')
        ->and($cards[0]['options'])->toBe([
            ['label' => 'الرانك', 'value' => 'رانك 2'],
            ['label' => 'السرعة', 'value' => 'مستعجل'],
        ]);
});

test('each service question offers its own card', function () {
    expect(assistantCards('كم سعر تحديات SBC')[0]['url'])->toBe('/sbc')
        ->and(assistantCards('ابغى تصعيد رايفلز')[0]['url'])->toBe('/rivals')
        ->and(assistantCards('ابغى فوت شامبيونز رانك 1')[0]['url'])->toBe('/fut-champions?rank=1');
});

test('a policy question offers no card', function () {
    // A warranty answer is support, not an invitation to buy.
    expect(assistantCards('كم مدة الضمان بعد الشحن؟'))->toBe([]);
});

test('a greeting offers no card', function () {
    expect(assistantCards('السلام عليكم'))->toBe([]);
});

test('the coins card is never duplicated by two coins topics', function () {
    $cards = assistantCards('ابغى كوينز والشحن السريع كم ياخذ وقت');

    expect(array_column($cards, 'id'))->toBe(['coins']);
});

test('cards never carry a price', function () {
    foreach (assistantCards('ابغى مليون كوينز') as $card) {
        expect($card['subtitle'])->not->toMatch('/\d+\s*(ريال|SAR|\$)/')
            ->and($card)->not->toHaveKey('price');
    }
});

test('every card image exists in the public build', function () {
    // A typo here ships a broken image into a customer's chat, and nothing
    // else would catch it: the path is a plain string.
    foreach (['ابغى كوينز', 'تحديات SBC', 'رايفلز', 'فوت شامبيونز'] as $question) {
        foreach (assistantCards($question) as $card) {
            expect($card['image'])->toStartWith('/images/')
                ->and(file_exists(public_path(ltrim($card['image'], '/'))))
                ->toBeTrue("missing image {$card['image']}");
        }
    }
});

test('card URLs always remain same-origin relative paths starting with slash', function () {
    foreach ([
        'ابي 500 الف كوينز بلايستيشن سريع',
        'من ٥ لإيليت',
        'ابغى فوت شامبيونز رانك 2 عاجل',
        'ابغى كوينز',
        'تحديات SBC',
    ] as $question) {
        foreach (['ar', 'en'] as $locale) {
            foreach (assistantCards($question, $locale) as $card) {
                expect($card['url'])->toStartWith('/')
                    ->and($card['url'])->not->toStartWith('//');
            }
        }
    }
});

test('an order-status question offers no buy card', function () {
    expect(assistantCards('شيك طلبي وقولي وين وصل'))->toBe([]);
});

test('asking what the store sells earns the whole menu as cards', function () {
    // "الخدمات" used to resolve to no topic at all, so the reply carried no
    // card and the model answered from general knowledge.
    foreach (['الخدمات', 'وش الخدمات عندكم', 'what services do you offer'] as $text) {
        $cards = app(BuildAssistantCards::class)->execute($text, 'ar');

        expect(array_column($cards, 'id'))
            ->toBe(['coins', 'sbc', 'rivals', 'fut_champions'], $text);
    }
});

test('the menu cards link to real storefront paths and carry no preselection', function () {
    foreach (app(BuildAssistantCards::class)->execute('الخدمات', 'ar') as $card) {
        expect($card['url'])->toStartWith('/')
            ->and($card['url'])->not->toStartWith('//')
            ->and($card['options'])->toBe([])
            ->and($card['title'])->not->toBe('');
    }
});

test('naming one service still earns only that service', function () {
    $cards = app(BuildAssistantCards::class)->execute('ابي كوينز', 'ar');

    expect(array_column($cards, 'id'))->toBe(['coins']);
});

test('a support question still earns no card', function () {
    expect(app(BuildAssistantCards::class)->execute('كم مدة الضمان؟', 'ar'))->toBe([]);
});
