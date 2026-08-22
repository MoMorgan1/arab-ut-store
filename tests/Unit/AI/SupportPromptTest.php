<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

test('each versioned support prompt loads as unresolved-placeholder-free plain text', function (string $version) {
    $prompt = file_get_contents(resource_path("ai-assistant/prompts/{$version}.md"));

    expect($prompt)->toBeString()
        ->and($prompt)->not->toBe('')
        ->and(mb_check_encoding($prompt, 'UTF-8'))->toBeTrue()
        ->and($prompt)->not->toMatch('/\{\{[^}]+\}\}|\{[^}]+\}/')
        ->and($prompt)->not->toMatch('/<[^>]+>/');
})->with(['support-v1', 'support-v2']);

test('the configured prompt version exists and support-v2 carries the mixed-language contract', function () {
    $configured = config('ai-assistant.prompt_version');
    $prompt = file_get_contents(resource_path("ai-assistant/prompts/{$configured}.md"));

    expect($configured)->toBe('support-v2')
        ->and($prompt)->toContain('mixes Arabic and English')
        ->and($prompt)->toContain('MUST also mix both languages')
        ->and($prompt)->toContain('Never invent or imply a live price')
        ->and($prompt)->toContain('Never ask for or repeat passwords');
});
