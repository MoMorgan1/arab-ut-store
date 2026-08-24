<?php

use App\Support\HandoffPhrases;

it('matches a customer asking for a person', function (string $text): void {
    expect(HandoffPhrases::matches($text))->toBeTrue();
})->with([
    'أبي أكلم موظف',
    'ودني على خدمة العملاء',
    'أبي أتكلم مع شخص حقيقي',
    'الدعم لو سمحت',
    'الدّعم لو سمحت',
    'ابي احد من الفريق',
    'can I talk to a human',
    'let me speak to someone',
    'I want a real person',
    'connect me to an agent',
]);

it('does not match an ordinary question', function (string $text): void {
    expect(HandoffPhrases::matches($text))->toBeFalse();
})->with([
    'كم سعر ٥٠٠ ألف كوينز؟',
    'متى يوصل طلبي؟',
    'ابي فوت شامبيونز رانك 1',
    'how long does delivery take',
    'is the service available on Xbox',
    'do you have a management page',
]);

it('does not fire on a bare noun with no request around it', function (string $text): void {
    // Every one of these opens a real ticket if the matcher keys on the noun
    // alone. The first is not hypothetical: it is fixture text from
    // AgentMessageEligibilityTest, and an earlier version of this matcher fired
    // on it, which is how the over-eager rule was caught.
    expect(HandoffPhrases::matches($text))->toBeFalse();
})->with([
    'Original agent request',
    'what does the agent do exactly',
    'my user agent string is wrong',
    'is this an automated agent or not',
    'متى يشتغل الدعم؟',
    'صفحة الدعم ما تفتح عندي',
]);

it('still fires when the request is real, in either word order', function (string $text): void {
    expect(HandoffPhrases::matches($text))->toBeTrue();
})->with([
    'can I talk to a human please',
    'I want to speak to an agent',
    'connect me with someone',
    'الدعم لو سمحت',
    'ممكن اكلم الدعم',
    'ابي اتواصل مع شخص',
]);
