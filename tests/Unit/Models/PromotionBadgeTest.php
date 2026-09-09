<?php

use App\Models\Promotion;

test('the badge prefers the requested locale, then the other, then the name', function (): void {
    $promotion = new Promotion;
    $promotion->forceFill(['badge_ar' => 'خصم', 'badge_en' => 'Sale', 'name_ar' => 'اسم', 'name_en' => 'Name']);

    expect($promotion->badgeFor('ar'))->toBe('خصم')
        ->and($promotion->badgeFor('en'))->toBe('Sale');

    $promotion->badge_ar = '  ';
    expect($promotion->badgeFor('ar'))->toBe('Sale');

    $promotion->badge_en = '';
    expect($promotion->badgeFor('ar'))->toBe('اسم')
        ->and($promotion->badgeFor('en'))->toBe('Name');
});
