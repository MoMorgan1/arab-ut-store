<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

test('each versioned support prompt loads as unresolved-placeholder-free plain text', function (string $version) {
    $prompt = file_get_contents(resource_path("ai-assistant/prompts/{$version}.md"));

    // `<store_knowledge>` is the deliberate delimiter around injected topics;
    // every other angle-bracket run would be an unresolved template or markup.
    $withoutDelimiters = str_replace(
        ['<store_knowledge>', '</store_knowledge>', '<live_prices>'],
        '',
        (string) $prompt,
    );

    expect($prompt)->toBeString()
        ->and($prompt)->not->toBe('')
        ->and(mb_check_encoding($prompt, 'UTF-8'))->toBeTrue()
        ->and($prompt)->not->toMatch('/\{\{[^}]+\}\}|\{[^}]+\}/')
        ->and($withoutDelimiters)->not->toMatch('/<[^>]+>/');
})->with(['support-v1', 'support-v2', 'support-v3', 'support-v4']);

test('the configured prompt version exists and carries the mixed-language and grounding contracts', function () {
    $configured = config('ai-assistant.prompt_version');
    $prompt = file_get_contents(resource_path("ai-assistant/prompts/{$configured}.md"));

    expect($configured)->toBe('support-v4')
        ->and($prompt)->toContain('mixes Arabic and English')
        ->and($prompt)->toContain('MUST also mix both languages')
        ->and($prompt)->toContain('Never derive a price for a quantity')
        ->and($prompt)->toContain('Never invent or imply availability')
        ->and($prompt)->toContain('Never ask for or repeat passwords')
        ->and($prompt)->toContain('<store_knowledge>')
        ->and($prompt)->toContain('follow the Arabic wording')
        ->and($prompt)->toContain('<live_prices>')
        ->and($prompt)->toContain('Quote them EXACTLY as written');
});
