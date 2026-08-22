<?php

test('versioned support prompt loads as unresolved-placeholder-free plain text', function () {
    $prompt = file_get_contents(resource_path('ai-assistant/prompts/support-v1.md'));

    expect($prompt)->toBeString()
        ->and($prompt)->not->toBe('')
        ->and(mb_check_encoding($prompt, 'UTF-8'))->toBeTrue()
        ->and($prompt)->not->toMatch('/\{\{[^}]+\}\}|\{[^}]+\}/')
        ->and($prompt)->not->toMatch('/<[^>]+>/');
});
