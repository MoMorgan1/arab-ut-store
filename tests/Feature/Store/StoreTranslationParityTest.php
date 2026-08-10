<?php

/** @return list<string> */
function translationLeafKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = ltrim("{$prefix}.{$key}", '.');

        if (is_array($value)) {
            $keys = [...$keys, ...translationLeafKeys($value, $path)];

            continue;
        }

        expect($value)->toBeString("Translation [{$path}] must be a string.");
        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

/** @return list<string> */
function translationTokens(string $value): array
{
    preg_match_all('/:([a-z_]+)/i', $value, $matches);
    $tokens = $matches[1];
    sort($tokens);

    return $tokens;
}

test('Arabic and English store translation leaves and placeholders stay in parity', function () {
    /** @var array<string, mixed> $arabic */
    $arabic = require lang_path('ar/store.php');
    /** @var array<string, mixed> $english */
    $english = require lang_path('en/store.php');
    $arabicKeys = translationLeafKeys($arabic);
    $englishKeys = translationLeafKeys($english);

    expect($arabicKeys)->toBe($englishKeys);

    foreach ($arabicKeys as $key) {
        expect(translationTokens(data_get($arabic, $key)))
            ->toBe(translationTokens(data_get($english, $key)), "Placeholder mismatch at [{$key}].");
    }

    expect(translationTokens(data_get($english, 'delivery.eta')))->toBe(['minutes'])
        ->and(translationTokens(data_get($english, 'accessibility.steps')))->toBe(['current', 'total']);
});

test('Arabic and English shell translation leaves and placeholders stay in parity', function () {
    /** @var array<string, mixed> $arabic */
    $arabic = require lang_path('ar/ui.php');
    /** @var array<string, mixed> $english */
    $english = require lang_path('en/ui.php');
    $arabicKeys = translationLeafKeys($arabic);
    $englishKeys = translationLeafKeys($english);

    expect($arabicKeys)->toBe($englishKeys);

    foreach ($arabicKeys as $key) {
        expect(translationTokens(data_get($arabic, $key)))
            ->toBe(translationTokens(data_get($english, $key)), "Placeholder mismatch at [{$key}].");
    }

    expect(data_get($arabic, 'simple_pages.cart.title'))->toBe('السلة')
        ->and(data_get($english, 'simple_pages.cart.title'))->toBe('Cart')
        ->and(translationTokens(data_get($arabic, 'footer.copyright')))->toBe(['year']);
});

test('Arabic customer copy consistently calls the service كوينز', function () {
    /** @var array<string, mixed> $arabic */
    $arabic = require lang_path('ar/store.php');
    $serialized = json_encode($arabic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect(data_get($arabic, 'hero.title'))->toBe('كوينز فيفا 27')
        ->and(data_get($arabic, 'coins_section.title'))->toBe('اطلب الكوينز')
        ->and(data_get($arabic, 'amount_copy.label'))->toBe('كمية الكوينز')
        ->and($serialized)->not->toContain('عملات')
        ->and($serialized)->not->toContain('العملات');
});

test('the configurator uses the approved WordPress annotations', function () {
    /** @var array<string, mixed> $arabic */
    $arabic = require lang_path('ar/store.php');
    /** @var array<string, mixed> $english */
    $english = require lang_path('en/store.php');

    expect(data_get($arabic, 'amount_copy.help'))->toBe('اكتب الكمية اللي تبيها.')
        ->and(data_get($arabic, 'delivery.badges.normal'))->toBe('ميزانية أقل')
        ->and(data_get($arabic, 'delivery.badges.fast'))->toBe('موصى به')
        ->and(data_get($english, 'delivery.badges.fast'))->toBe('Recommended')
        ->and(data_get($arabic, 'quote.refreshing'))->toBe('نحدّث السعر…')
        ->and(data_get($english, 'quote.refreshing'))->toBe('Refreshing price…')
        ->and(data_get($arabic, 'actions'))->not->toHaveKey('restart')
        ->and(data_get($english, 'actions'))->not->toHaveKey('restart');
});

test('the hero uses the approved bilingual copy and proof contract', function () {
    /** @var array<string, mixed> $arabic */
    $arabic = require lang_path('ar/store.php');
    /** @var array<string, mixed> $english */
    $english = require lang_path('en/store.php');

    expect(data_get($arabic, 'hero.badge'))->toBe('كل اللي تحتاجه في FC 27 بمكان واحد')
        ->and(data_get($arabic, 'hero.title'))->toBe('كوينز فيفا 27')
        ->and(data_get($arabic, 'hero.accent'))->toBe('بأفضل الأسعار')
        ->and(data_get($arabic, 'hero.cta'))->toBe('اختر كوينزك')
        ->and(data_get($english, 'hero.cta'))->toBe('Choose your Coins')
        ->and(data_get($arabic, 'hero.stats'))->toHaveCount(4)
        ->and(data_get($english, 'hero.stats'))->toHaveCount(4);
});
