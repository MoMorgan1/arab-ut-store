<?php

declare(strict_types=1);

/**
 * The knowledge file is customer-facing copy that Luna quotes verbatim, so its
 * integrity is a test concern: a malformed entry, a leaked phone number, or a
 * link to the retired storefront would reach customers as a confident answer.
 */
function supportKnowledge(): array
{
    $path = resource_path('ai-assistant/knowledge/arab-ut.json');

    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}

it('declares a version and an authoritative locale', function (): void {
    $knowledge = supportKnowledge();

    expect($knowledge['version'])->toBe('knowledge.v1')
        ->and($knowledge['locale_authority'])->toBe('ar');
});

it('gives every topic a unique id and both locales', function (): void {
    $topics = supportKnowledge()['topics'];

    expect($topics)->not->toBeEmpty();

    $ids = array_column($topics, 'id');

    expect($ids)->toHaveCount(count(array_unique($ids)));

    foreach ($topics as $topic) {
        foreach (['id', 'url', 'faq', 'keywords_ar', 'keywords_en', 'title_ar', 'body_ar', 'title_en', 'body_en'] as $key) {
            expect($topic)->toHaveKey($key);
        }

        expect($topic['title_ar'])->not->toBe('')
            ->and($topic['body_ar'])->not->toBe('')
            ->and($topic['title_en'])->not->toBe('')
            ->and($topic['body_en'])->not->toBe('')
            ->and($topic['faq'])->toBeBool()
            ->and($topic['keywords_ar'])->not->toBeEmpty()
            ->and($topic['keywords_en'])->not->toBeEmpty();
    }
});

it('points every topic at a storefront route on this site', function (): void {
    foreach (supportKnowledge()['topics'] as $topic) {
        expect($topic['url'])->toStartWith('/');
    }
});

it('never publishes support phone numbers or retired storefront links', function (): void {
    foreach (supportKnowledge()['topics'] as $topic) {
        foreach (['body_ar', 'body_en'] as $field) {
            expect($topic[$field])->not->toMatch('/\+?966\s?\d/')
                ->and($topic[$field])->not->toContain('arab-ut.com/FC-COINS');
        }
    }
});

it('keeps the corpus small enough to stay affordable in a prompt', function (): void {
    $topics = supportKnowledge()['topics'];

    $characters = array_sum(array_map(
        static fn (array $topic): int => mb_strlen($topic['title_ar'].$topic['body_ar']),
        $topics,
    ));

    // Arabic runs roughly 2.5 characters per token. Selection keeps only a few
    // topics per turn, but a runaway corpus would still break the cost gate.
    expect(intdiv($characters, 2))->toBeLessThan(12000);
});
